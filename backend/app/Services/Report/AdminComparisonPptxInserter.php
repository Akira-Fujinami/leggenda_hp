<?php

namespace App\Services\Report;

use App\Exceptions\Report\ComparisonSlideInsertionException;
use ZipArchive;

/**
 * 依頼BG: 既存の営業資料(PPTX)に、比較スライド1枚を「参照元」ページの
 * 直前へ差し込む。
 *
 * 【最重要、依頼BG-1】既存デッキをPhpOffice\PhpPresentationで開いて保存し
 * 直すことは絶対にしない ―― PowerPoint2007リーダーはグラフ・埋め込み
 * ブック・ノート・テーマを扱えず、読み込んで書き戻すとこれらが失われる。
 * PPTXはZIPであるという前提のもと、ZipArchiveで次の3パーツだけを追加・
 * 上書きし、それ以外の既存パーツは1バイトも触らない:
 *   1. ppt/slides/slideN.xml (新規追加)
 *   2. ppt/slides/_rels/slideN.xml.rels (新規追加)
 *   3. [Content_Types].xml (Overrideを1行追加)
 *   4. ppt/_rels/presentation.xml.rels (Relationshipを1行追加)
 *   5. ppt/presentation.xml (sldIdLstに1行挿入)
 * (3〜5は「新規追加」ではなく「既存パーツの上書き」だが、いずれも
 * 既存の内容をそのまま残したうえで1行足すだけの最小差分にする ――
 * DOMDocumentで読み込んで再シリアライズすると無関係な整形が変わりうる
 * ため、文字列操作で該当箇所にだけ挿入する)。
 *
 * 既存スライドのファイル名(slide1.xml等)は一切振り直さない ―― 表示順は
 * sldIdLstの並びだけで決まるため、ファイル番号を詰める必要が無い
 * (振り直すとnotesSlidesのrelsまで壊れる、依頼者指定)。
 */
class AdminComparisonPptxInserter
{
    private const CONTENT_TYPE_SLIDE = 'application/vnd.openxmlformats-officedocument.presentationml.slide+xml';

    private const REL_TYPE_SLIDE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide';

    private const REL_TYPE_SLIDE_LAYOUT = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout';

    /**
     * 依頼BI-3: 差し込みは行わず、検証だけを行う。比較作成フォームの
     * 送信時点(BI-3)・詳細画面の添付欄(AnalysisAttachmentController)の
     * 両方から、実際の差し込み(insert())と全く同じ判定ロジックを呼ぶための
     * 入口 ―― 判定を二重に書かない。検証をパスすれば正常終了(戻り値なし)、
     * 満たさなければinsert()と同じComparisonSlideInsertionExceptionを
     * 投げる(スライドサイズ不一致・参照元ページが見つからない、等)。
     *
     * 【重要】これは前倒しの検証であって、ダウンロード時(insert())の検証を
     * 置き換えるものではない ―― 資料は差し替えられるし、config
     * の検出語も変わりうるため、insert()側の検証はそのまま残す(依頼者指定)。
     */
    public function validate(string $deckPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($deckPath) !== true) {
            throw new ComparisonSlideInsertionException('営業資料(PPTX)を開けませんでした。ファイルが壊れている可能性があります。');
        }

        try {
            $this->assertReferencePageExistsAndSizeMatches($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{0: string, 1: string, 2: list<array{id: int, rId: string}>, 3: array<string, string>, 4: int}
     *              [presentation.xmlの中身, presentation.xml.relsの中身, sldIdLst, rId=>Target, 挿入位置(0始まり)]
     */
    private function assertReferencePageExistsAndSizeMatches(ZipArchive $zip): array
    {
        $presentationXml = $this->readEntry($zip, 'ppt/presentation.xml');
        $presentationRelsXml = $this->readEntry($zip, 'ppt/_rels/presentation.xml.rels');

        $this->assertSlideSize($presentationXml);

        $sldIds = $this->parseSlideIdList($presentationXml);
        if ($sldIds === []) {
            throw new ComparisonSlideInsertionException('営業資料にスライドが1枚もありません。');
        }

        $rIdToTarget = $this->parseRelationshipTargets($presentationRelsXml);

        $insertionIndex = $this->findReferencePageIndex($zip, $sldIds, $rIdToTarget);
        if ($insertionIndex === null) {
            $keywords = implode('/', (array) config('admin_comparison_pptx.reference_page_keywords'));
            throw new ComparisonSlideInsertionException(
                "営業資料に「{$keywords}」のページが見つかりませんでした。差し込み位置を判断できないため中止しました。",
            );
        }

        return [$presentationXml, $presentationRelsXml, $sldIds, $rIdToTarget, $insertionIndex];
    }

    /**
     * @return string  差し込み後のPPTXを書き出した一時ファイルのパス(呼び出し側が削除すること)
     */
    public function insert(string $baseDeckPath, string $comparisonSlideBytes): string
    {
        [$comparisonSlideXml, ] = $this->extractComparisonSlideParts($comparisonSlideBytes);

        $outputPath = tempnam(sys_get_temp_dir(), 'pptx-merged');
        if (! copy($baseDeckPath, $outputPath)) {
            @unlink($outputPath);
            throw new ComparisonSlideInsertionException('営業資料の一時コピーに失敗しました。');
        }

        $zip = new ZipArchive();
        if ($zip->open($outputPath) !== true) {
            @unlink($outputPath);
            throw new ComparisonSlideInsertionException('営業資料(PPTX)を開けませんでした。ファイルが壊れている可能性があります。');
        }

        try {
            [$presentationXml, $presentationRelsXml, $sldIds, $rIdToTarget, $insertionIndex] = $this->assertReferencePageExistsAndSizeMatches($zip);
            $contentTypesXml = $this->readEntry($zip, '[Content_Types].xml');

            $neighborIndex = $insertionIndex > 0 ? $insertionIndex - 1 : $insertionIndex;
            $neighborTarget = $rIdToTarget[$sldIds[$neighborIndex]['rId']] ?? null;
            if ($neighborTarget === null) {
                throw new ComparisonSlideInsertionException('挿入位置の隣のスライドを特定できませんでした。');
            }
            $layoutTarget = $this->findSlideLayoutTarget($zip, $neighborTarget);
            if ($layoutTarget === null) {
                throw new ComparisonSlideInsertionException('挿入位置の隣のスライドが使っているレイアウトを特定できませんでした。');
            }

            $newSlideNumber = $this->nextSlideNumber($zip);
            $newRId = $this->nextRelationshipId($presentationRelsXml);
            $newSldId = $this->nextSlideId($sldIds);

            $newSlidePath = "ppt/slides/slide{$newSlideNumber}.xml";
            $newSlideRelsPath = "ppt/slides/_rels/slide{$newSlideNumber}.xml.rels";
            $newSlideRelsXml = $this->buildSlideRelsXml($layoutTarget);

            $updatedContentTypesXml = $this->insertContentTypeOverride($contentTypesXml, $newSlidePath);
            $updatedPresentationRelsXml = $this->insertPresentationRelationship($presentationRelsXml, $newRId, $newSlideNumber);
            $updatedPresentationXml = $this->insertSldId($presentationXml, $sldIds, $insertionIndex, $newSldId, $newRId);

            if (! $zip->addFromString($newSlidePath, $comparisonSlideXml)
                || ! $zip->addFromString($newSlideRelsPath, $newSlideRelsXml)
                || ! $zip->addFromString('[Content_Types].xml', $updatedContentTypesXml)
                || ! $zip->addFromString('ppt/_rels/presentation.xml.rels', $updatedPresentationRelsXml)
                || ! $zip->addFromString('ppt/presentation.xml', $updatedPresentationXml)
            ) {
                throw new ComparisonSlideInsertionException('PPTXへの書き込みに失敗しました。');
            }
        } catch (\Throwable $e) {
            $zip->close();
            // 途中で中止した場合、コピーした一時ファイルを残さない
            // (呼び出し側は$outputPathを受け取れないため、ここで必ず消す)。
            @unlink($outputPath);
            throw $e;
        }

        $zip->close();

        return $outputPath;
    }

    /**
     * @return array{0: string, 1: string}  [slide1.xmlの中身, slide1.xml.relsの中身]
     */
    private function extractComparisonSlideParts(string $comparisonSlideBytes): array
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'pptx-src');
        file_put_contents($tmpPath, $comparisonSlideBytes);

        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            @unlink($tmpPath);
            throw new ComparisonSlideInsertionException('比較スライドの生成結果を読み込めませんでした。');
        }

        $slideXml = $zip->getFromName('ppt/slides/slide1.xml');
        $relsXml = $zip->getFromName('ppt/slides/_rels/slide1.xml.rels');
        $zip->close();
        @unlink($tmpPath);

        if ($slideXml === false || $relsXml === false) {
            throw new ComparisonSlideInsertionException('比較スライドの生成結果の形式が想定外です。');
        }

        // 依頼BG-2: 差し込む比較スライドが画像・グラフ・埋め込みを一切
        // 参照していないことを実行時にも確認する(生成側の実装が将来変わり
        // 画像等を持つようになった場合に、無言で欠落した状態で差し込んで
        // しまわないための安全弁)。r:id/r:embed/r:link参照が無いことは
        // AdminComparisonPptxGenerator側の設計(RichText/AutoShapeのみ、
        // 画像・グラフ・ハイパーリンクを使わない)により保証されている。
        if (preg_match('/\br:(id|embed|link)="/', $slideXml) === 1) {
            throw new ComparisonSlideInsertionException(
                '比較スライドが画像・グラフ等の外部パーツを参照しているため、この単純な差し込み方式では対応できません。',
            );
        }

        return [$slideXml, $relsXml];
    }

    private function readEntry(ZipArchive $zip, string $name): string
    {
        $content = $zip->getFromName($name);
        if ($content === false) {
            throw new ComparisonSlideInsertionException("営業資料の形式が想定外です({$name}が見つかりません)。");
        }

        return $content;
    }

    private function assertSlideSize(string $presentationXml): void
    {
        if (preg_match('/<p:sldSz\s+cx="(\d+)"\s+cy="(\d+)"/', $presentationXml, $m) !== 1) {
            throw new ComparisonSlideInsertionException('営業資料のスライドサイズを読み取れませんでした。');
        }

        $cx = (int) $m[1];
        $cy = (int) $m[2];
        $requiredCx = (int) config('admin_comparison_pptx.required_slide_width_emu');
        $requiredCy = (int) config('admin_comparison_pptx.required_slide_height_emu');

        if ($cx !== $requiredCx || $cy !== $requiredCy) {
            throw new ComparisonSlideInsertionException(sprintf(
                'この資料は%sです。%sの資料が必要です。',
                $this->slideSizeLabel($cx, $cy),
                $this->slideSizeLabel($requiredCx, $requiredCy),
            ));
        }
    }

    /**
     * 依頼BI-3: エラー文言に実際の寸法を示すこと(依頼者指定の例文
     * 「この資料は 4:3（25.40 × 19.05 cm）です。」)。4:3/16:9/16:10等の
     * よく使われる比率であれば比率名も添え、それ以外はcmの寸法のみ示す。
     */
    private function slideSizeLabel(int $cx, int $cy): string
    {
        $cm = sprintf('%.2f × %.2f cm', $cx / 360000, $cy / 360000);
        $ratio = $this->slideAspectRatioName($cx, $cy);

        return $ratio !== null ? "{$ratio}（{$cm}）" : $cm;
    }

    private function slideAspectRatioName(int $cx, int $cy): ?string
    {
        $divisor = $this->gcd($cx, $cy);
        $ratioW = intdiv($cx, $divisor);
        $ratioH = intdiv($cy, $divisor);

        return match (true) {
            $ratioW === 4 && $ratioH === 3 => '4:3',
            $ratioW === 16 && $ratioH === 9 => '16:9',
            $ratioW === 16 && $ratioH === 10 => '16:10',
            default => null,
        };
    }

    private function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : $this->gcd($b, $a % $b);
    }

    /**
     * @return list<array{id: int, rId: string}>  sldIdLstに現れる順(=表示順)
     */
    private function parseSlideIdList(string $presentationXml): array
    {
        if (preg_match('/<p:sldIdLst>(.*?)<\/p:sldIdLst>/s', $presentationXml, $listMatch) !== 1) {
            return [];
        }

        preg_match_all('/<p:sldId\s+id="(\d+)"\s+r:id="(rId\d+)"\s*\/>/', $listMatch[1], $entries, PREG_SET_ORDER);

        return array_map(fn (array $entry) => ['id' => (int) $entry[1], 'rId' => $entry[2]], $entries);
    }

    /**
     * @return array<string, string>  rId => Target
     */
    private function parseRelationshipTargets(string $relsXml): array
    {
        preg_match_all('/<Relationship\s+Id="(rId\d+)"[^>]*Target="([^"]+)"/', $relsXml, $entries, PREG_SET_ORDER);

        $map = [];
        foreach ($entries as $entry) {
            $map[$entry[1]] = $entry[2];
        }

        return $map;
    }

    /**
     * 依頼BG-2: 各スライドのテキストを末尾側(表示順の最後)から探し、
     * 最初に「参照元」等の語が見つかったスライドの位置(0始まり)を返す。
     * 見つからなければnull(呼び出し側でエラーにする ―― 位置を推測しない)。
     *
     * @param  list<array{id: int, rId: string}>  $sldIds
     * @param  array<string, string>  $rIdToTarget
     */
    private function findReferencePageIndex(ZipArchive $zip, array $sldIds, array $rIdToTarget): ?int
    {
        $keywords = array_map('mb_strtolower', (array) config('admin_comparison_pptx.reference_page_keywords'));
        if ($keywords === []) {
            return null;
        }

        for ($index = count($sldIds) - 1; $index >= 0; $index--) {
            $target = $rIdToTarget[$sldIds[$index]['rId']] ?? null;
            if ($target === null) {
                continue;
            }

            $slidePath = $this->resolveSlidePath($target);
            $slideXml = $zip->getFromName($slidePath);
            if ($slideXml === false) {
                continue;
            }

            $text = mb_strtolower($this->extractText($slideXml));
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($text, $keyword)) {
                    return $index;
                }
            }
        }

        return null;
    }

    private function extractText(string $slideXml): string
    {
        preg_match_all('/<a:t>(.*?)<\/a:t>/s', $slideXml, $matches);

        return implode(' ', array_map(
            fn (string $t) => html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8'),
            $matches[1],
        ));
    }

    /**
     * ppt/_rels/presentation.xml.rels のTargetは"slides/slide1.xml"のように
     * ppt/からの相対パスのため、ZipArchiveのエントリ名(ppt/slides/slide1.xml)
     * に変換する。
     */
    private function resolveSlidePath(string $target): string
    {
        return 'ppt/'.ltrim($target, '/');
    }

    /**
     * 指定したスライド(ppt/slides/slideM.xml)自身のrelsから、そのスライドが
     * 使っているslideLayoutのTarget(例: "../slideLayouts/slideLayout3.xml")を
     * そのまま返す。依頼BG-2: 差し込むスライドのレイアウト参照先は、
     * 決め打ちせずこれをそのまま使う。
     */
    private function findSlideLayoutTarget(ZipArchive $zip, string $slideTarget): ?string
    {
        $slidePath = $this->resolveSlidePath($slideTarget);
        $slideFileName = basename($slidePath);
        $relsPath = 'ppt/slides/_rels/'.$slideFileName.'.rels';

        $relsXml = $zip->getFromName($relsPath);
        if ($relsXml === false) {
            return null;
        }

        if (preg_match('/<Relationship[^>]*Type="'.preg_quote(self::REL_TYPE_SLIDE_LAYOUT, '/').'"[^>]*Target="([^"]+)"/', $relsXml, $m) === 1) {
            return $m[1];
        }

        // Type/Targetの属性順が逆の場合にも対応する。
        if (preg_match('/<Relationship\s+Id="[^"]+"\s+Type="([^"]+)"\s+Target="([^"]+)"/', $relsXml, $m) === 1 && $m[1] === self::REL_TYPE_SLIDE_LAYOUT) {
            return $m[2];
        }

        return null;
    }

    private function nextSlideNumber(ZipArchive $zip): int
    {
        $max = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max + 1;
    }

    private function nextRelationshipId(string $relsXml): string
    {
        preg_match_all('/Id="rId(\d+)"/', $relsXml, $m);
        $max = $m[1] === [] ? 0 : max(array_map('intval', $m[1]));

        return 'rId'.($max + 1);
    }

    /**
     * @param  list<array{id: int, rId: string}>  $sldIds
     */
    private function nextSlideId(array $sldIds): int
    {
        $max = $sldIds === [] ? 0 : max(array_column($sldIds, 'id'));

        // OOXML仕様: sldId/@idは256以上(1〜255はスライドマスター/レイアウトの
        // 予約範囲)。既存デッキは通常既に256以上のため実質max+1になる。
        return max(256, $max + 1);
    }

    private function buildSlideRelsXml(string $layoutTarget): string
    {
        $escapedTarget = htmlspecialchars($layoutTarget, ENT_QUOTES | ENT_XML1);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="'.self::REL_TYPE_SLIDE_LAYOUT.'" Target="'.$escapedTarget.'"/>'
            .'</Relationships>';
    }

    private function insertContentTypeOverride(string $contentTypesXml, string $newSlidePath): string
    {
        $override = '<Override PartName="/'.$newSlidePath.'" ContentType="'.self::CONTENT_TYPE_SLIDE.'"/>';

        if (! str_contains($contentTypesXml, '</Types>')) {
            throw new ComparisonSlideInsertionException('[Content_Types].xmlの形式が想定外です。');
        }

        return str_replace('</Types>', $override.'</Types>', $contentTypesXml);
    }

    private function insertPresentationRelationship(string $relsXml, string $newRId, int $newSlideNumber): string
    {
        $relationship = '<Relationship Id="'.$newRId.'" Type="'.self::REL_TYPE_SLIDE.'" Target="slides/slide'.$newSlideNumber.'.xml"/>';

        if (! str_contains($relsXml, '</Relationships>')) {
            throw new ComparisonSlideInsertionException('ppt/_rels/presentation.xml.relsの形式が想定外です。');
        }

        return str_replace('</Relationships>', $relationship.'</Relationships>', $relsXml);
    }

    /**
     * @param  list<array{id: int, rId: string}>  $sldIds
     */
    private function insertSldId(string $presentationXml, array $sldIds, int $insertionIndex, int $newSldId, string $newRId): string
    {
        preg_match_all('/<p:sldId\s+id="\d+"\s+r:id="rId\d+"\s*\/>/', $presentationXml, $matches, PREG_OFFSET_CAPTURE);

        if (count($matches[0]) !== count($sldIds)) {
            throw new ComparisonSlideInsertionException('ppt/presentation.xmlのスライド一覧を正しく解析できませんでした。');
        }

        $targetMatch = $matches[0][$insertionIndex];
        $insertOffset = $targetMatch[1];
        $newElement = '<p:sldId id="'.$newSldId.'" r:id="'.$newRId.'"/>';

        return substr($presentationXml, 0, $insertOffset).$newElement.substr($presentationXml, $insertOffset);
    }
}
