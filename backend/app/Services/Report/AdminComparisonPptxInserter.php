<?php

namespace App\Services\Report;

use App\Exceptions\Report\ComparisonSlideInsertionException;
use ZipArchive;

/**
 * 依頼BG: 既存の営業資料(PPTX)に、比較スライドを「参照元」ページの直前へ
 * 差し込む。
 *
 * 依頼BZ-2(2026-09-15): 差し込むスライドを1枚から複数枚(説明ページ→
 * 比較ページの順)に対応させた。insert()は1枚のPPTXバイト列ではなく、
 * 差し込む順で並んだ配列(list<string>)を受け取る ―― 各要素は
 * AdminComparisonPptxGenerator::generate()/generateExplanationSlide()の
 * 戻り値(スライド1枚だけのPPTX)。
 *
 * 【最重要、依頼BG-1】既存デッキをPhpOffice\PhpPresentationで開いて保存し
 * 直すことは絶対にしない ―― PowerPoint2007リーダーはグラフ・埋め込み
 * ブック・ノート・テーマを扱えず、読み込んで書き戻すとこれらが失われる。
 * PPTXはZIPであるという前提のもと、ZipArchiveで次の3パーツだけを上書き
 * (どちらも既存の内容をそのまま残したうえで、スライド1枚につき1行ずつ
 * 追記する最小差分)し、スライドの枚数ぶんだけ新規パーツ(slideN.xml /
 * slideN.xml.rels)を追加する。それ以外の既存パーツは1バイトも触らない:
 *   - ppt/slides/slideN.xml (新規追加、スライド1枚につき1つ)
 *   - ppt/slides/_rels/slideN.xml.rels (新規追加、スライド1枚につき1つ)
 *   - [Content_Types].xml (Overrideをスライド枚数ぶん追加)
 *   - ppt/_rels/presentation.xml.rels (Relationshipをスライド枚数ぶん追加)
 *   - ppt/presentation.xml (sldIdLstにスライド枚数ぶん挿入)
 * (DOMDocumentで読み込んで再シリアライズすると無関係な整形が変わりうる
 * ため、文字列操作で該当箇所にだけ挿入する ―― 書き換えるファイルの数は
 * 何枚差し込んでも3つのまま増えない)。
 *
 * 依頼BZ-2で最も壊れやすかった点: スライド番号・rId・sldIdは、1枚ごとに
 * 進める必要がある(1回だけ計算して複数枚で使い回すと、同じ番号のパーツが
 * 衝突し壊れたPPTXになる)。insert()内のループで、各スライドを追加する
 * 都度、直前までの状態(更新済みのpresentation.xml/rels/[Content_Types].xml
 * 文字列と、sldIdLstの現在の並び)を次の反復へ引き継ぎ、その時点の最新値
 * から次の番号を計算し直す(nextSlideNumber/nextRelationshipId/
 * nextSlideIdをループ内で毎回呼ぶ)。挿入位置も、1枚差し込むたびに
 * 直後の位置へ進める(説明ページ→比較ページ→参照元、の順を保つため)。
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
     * @param  list<string>  $slideBytesList  差し込む順(説明ページ→比較ページ)に並んだ、スライド1枚だけのPPTXバイト列
     * @return string  差し込み後のPPTXを書き出した一時ファイルのパス(呼び出し側が削除すること)
     */
    public function insert(string $baseDeckPath, array $slideBytesList): string
    {
        if ($slideBytesList === []) {
            throw new ComparisonSlideInsertionException('差し込むスライドが指定されていません。');
        }

        $slideXmlList = array_map(fn (string $bytes) => $this->extractSingleSlide($bytes), $slideBytesList);

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

            // 依頼BZ-2: ここから先はループの反復ごとに更新していく「作業中」の
            // 状態。スライド番号は$nextSlideNumberBaseにインデックスを足す
            // だけでよい(このinsert()呼び出しの開始時点で存在した最大番号
            // より後ろを使うため、ループ内で衝突しない)が、rId・sldIdは
            // 直前の反復で追記した内容(文字列・配列)を見て初めて次の値が
            // 決まるため、1回だけ計算して使い回すことができない
            // ―― 毎回、直前までの最新の$presentationRelsXml/$sldIdsから
            // 計算し直す。挿入位置($insertionIndex)も、1枚追加するたびに
            // 1つ後ろへ進める(次のスライドを「いま追加した直後」=
            // 「参照元の直前」へ入れ続けるため)。
            $nextSlideNumberBase = $this->nextSlideNumber($zip);

            foreach ($slideXmlList as $i => $slideXml) {
                $newSlideNumber = $nextSlideNumberBase + $i;
                $newRId = $this->nextRelationshipId($presentationRelsXml);
                $newSldId = $this->nextSlideId($sldIds);

                $newSlidePath = "ppt/slides/slide{$newSlideNumber}.xml";
                $newSlideRelsPath = "ppt/slides/_rels/slide{$newSlideNumber}.xml.rels";
                $newSlideRelsXml = $this->buildSlideRelsXml($layoutTarget);

                $contentTypesXml = $this->insertContentTypeOverride($contentTypesXml, $newSlidePath);
                $presentationRelsXml = $this->insertPresentationRelationship($presentationRelsXml, $newRId, $newSlideNumber);
                $presentationXml = $this->insertSldId($presentationXml, $sldIds, $insertionIndex, $newSldId, $newRId);

                // insertSldId()は$sldIdsの件数とpresentationXml中の<p:sldId>
                // 件数が一致することを前提にしている。次の反復でも一致させ
                // 続けるため、いま追加した1件をここで$sldIdsにも反映する。
                array_splice($sldIds, $insertionIndex, 0, [['id' => $newSldId, 'rId' => $newRId]]);
                $insertionIndex++;

                if (! $zip->addFromString($newSlidePath, $slideXml) || ! $zip->addFromString($newSlideRelsPath, $newSlideRelsXml)) {
                    throw new ComparisonSlideInsertionException('PPTXへの書き込みに失敗しました。');
                }
            }

            if (! $zip->addFromString('[Content_Types].xml', $contentTypesXml)
                || ! $zip->addFromString('ppt/_rels/presentation.xml.rels', $presentationRelsXml)
                || ! $zip->addFromString('ppt/presentation.xml', $presentationXml)
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
     * @return string  slide1.xmlの中身
     */
    private function extractSingleSlide(string $slideBytes): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'pptx-src');
        file_put_contents($tmpPath, $slideBytes);

        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            @unlink($tmpPath);
            throw new ComparisonSlideInsertionException('差し込むスライドの生成結果を読み込めませんでした。');
        }

        $slideXml = $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();
        @unlink($tmpPath);

        if ($slideXml === false) {
            throw new ComparisonSlideInsertionException('差し込むスライドの生成結果の形式が想定外です。');
        }

        // 依頼BG-2: 差し込むスライドが画像・グラフ・埋め込みを一切
        // 参照していないことを実行時にも確認する(生成側の実装が将来変わり
        // 画像等を持つようになった場合に、無言で欠落した状態で差し込んで
        // しまわないための安全弁)。r:id/r:embed/r:link参照が無いことは
        // AdminComparisonPptxGenerator側の設計(RichText/AutoShapeのみ、
        // 画像・グラフ・ハイパーリンクを使わない)により保証されている。
        if (preg_match('/\br:(id|embed|link)="/', $slideXml) === 1) {
            throw new ComparisonSlideInsertionException(
                '差し込むスライドが画像・グラフ等の外部パーツを参照しているため、この単純な差し込み方式では対応できません。',
            );
        }

        return $slideXml;
    }

    private function readEntry(ZipArchive $zip, string $name): string
    {
        $content = $zip->getFromName($name);
        if ($content === false) {
            throw new ComparisonSlideInsertionException("営業資料の形式が想定外です({$name}が見つかりません)。");
        }

        return $content;
    }

    /**
     * @var array<string, float>  比率名 => cx/cy
     */
    private const KNOWN_ASPECT_RATIOS = [
        '4:3' => 4 / 3,
        '16:10' => 16 / 10,
        '16:9' => 16 / 9,
    ];

    /**
     * 依頼BK(2026-09-09): 本番で実物の16:9資料(cx=12191695、cy=6858000。
     * 要求値との差はcxのみ305EMU)が完全一致判定により「16:9でない」として
     * 弾かれた。cmでスライドサイズを指定するツール・書き出し経路の丸め等で
     * 見た目には判別できないごく小さなEMUのずれが実在するため、完全一致
     * ではなく設定した許容差(admin_comparison_pptx.slide_size_tolerance_emu、
     * 依頼BL-2で9144EMU=0.01インチに拡大)以内かどうかで判定する。幅・
     * 高さそれぞれに独立して適用する(比率で見ると、本番資料のように片方の
     * 軸だけがずれているケースの許容量が直感的に説明しづらくなるため)。
     *
     * 【重要、依頼BL-1】この寸法チェックは「同じ紙面サイズか」だけを見る
     * ―― 比率(縦横比)は一切見ない。比率名はエラーメッセージの文言を
     * 分かりやすくするためだけに使う(slideSizeMismatchMessage()参照)。
     * 通す/弾くの判定に比率を混ぜない ―― 比較スライドは絶対座標で置かれて
     * おり、はみ出すかどうかは実際の紙面サイズだけで決まるため。
     */
    private function assertSlideSize(string $presentationXml): void
    {
        $size = $this->parseSlideSize($presentationXml);
        if ($size === null) {
            throw new ComparisonSlideInsertionException('営業資料のスライドサイズを読み取れませんでした。');
        }

        [$cx, $cy] = $size;
        $requiredCx = (int) config('admin_comparison_pptx.required_slide_width_emu');
        $requiredCy = (int) config('admin_comparison_pptx.required_slide_height_emu');
        $tolerance = (int) config('admin_comparison_pptx.slide_size_tolerance_emu');

        if (abs($cx - $requiredCx) > $tolerance || abs($cy - $requiredCy) > $tolerance) {
            throw new ComparisonSlideInsertionException($this->slideSizeMismatchMessage($cx, $cy, $requiredCx, $requiredCy));
        }
    }

    /**
     * 依頼BK-3: <p:sldSz>の属性の並び("type"付き、cx/cyの順序が
     * 前後する等)に依存せず読み取る。まずタグ全体を(属性の並びを問わず)
     * 取り出し、そのうえでcx/cyをそれぞれ独立に探す ―― PowerPoint以外
     * (Googleスライド・Keynote・LibreOffice等)の書き出し順を前提にしない。
     * 読み取れない場合はnull(呼び出し側で「読み取れませんでした」にする。
     * 既定値で処理を続けない、依頼者指定)。
     *
     * @return array{0: int, 1: int}|null  [cx, cy]
     */
    private function parseSlideSize(string $presentationXml): ?array
    {
        if (preg_match('/<p:sldSz\b[^>]*\/>/', $presentationXml, $tagMatch) !== 1) {
            return null;
        }

        $cx = $this->extractIntAttribute($tagMatch[0], 'cx');
        $cy = $this->extractIntAttribute($tagMatch[0], 'cy');

        return ($cx !== null && $cy !== null) ? [$cx, $cy] : null;
    }

    private function extractIntAttribute(string $tag, string $attribute): ?int
    {
        if (preg_match('/\b'.preg_quote($attribute, '/').'="(\d+)"/', $tag, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * 依頼BL-1: 「比率は合うが寸法が違う」(例: 9144000×5143500、10×5.625in
     * ―― cx/cyの比はちょうど16/9だが、要求している13.333×7.5inとは別の
     * インチ数の16:9)場合に、依頼BKで直したのと同種の「16:9ではないと
     * 言われたが、これは16:9である」という混乱を再発させないため、3つの
     * 状態を区別して文言を出し分ける。
     *   1. 比率も一致 → 直しかたの一文を添える(「16:9ですが…」)
     *   2. 比率も不一致 → 従来通りの比較文言(依頼BK-2のEMU併記も維持)
     */
    private function slideSizeMismatchMessage(int $cx, int $cy, int $requiredCx, int $requiredCy): string
    {
        $actualRatioName = $this->slideAspectRatioName($cx, $cy);
        $requiredRatioName = $this->slideAspectRatioName($requiredCx, $requiredCy);
        // 依頼BK-2: cm表示(小数2桁)まで丸めると両側が同じ文字列になり
        // 「何が違うのか分からない文」になっていた(依頼者指摘の実例)。
        // cm表示が両側で一致する場合は、判別できるよう実際のEMU値も
        // 両側に添える(この出し分けのどちらのケースでも維持する)。
        $ambiguous = $this->cmLabel($cx, $cy) === $this->cmLabel($requiredCx, $requiredCy);

        if ($actualRatioName !== null && $actualRatioName === $requiredRatioName) {
            return sprintf(
                '%sですが、%sです。%sの資料が必要です。'
                .'PowerPointの［デザイン］→［スライドのサイズ］で、幅%s cm × 高さ%s cm に変更してください。',
                $actualRatioName,
                $this->sizeDetail($cx, $cy, $ambiguous),
                $this->sizeDetail($requiredCx, $requiredCy, $ambiguous),
                number_format($requiredCx / 360000, 2),
                number_format($requiredCy / 360000, 2),
            );
        }

        return sprintf(
            'この資料は%sです。%sの資料が必要です。',
            $this->slideSizeLabel($cx, $cy, $ambiguous),
            $this->slideSizeLabel($requiredCx, $requiredCy, $ambiguous),
        );
    }

    /**
     * 依頼BI-3: エラー文言に実際の寸法を示すこと(依頼者指定の例文
     * 「この資料は 4:3（25.40 × 19.05 cm）です。」)。4:3/16:9/16:10等の
     * よく使われる比率に近ければ(依頼BL-1: cx/cyの比そのもので判定する)
     * 比率名も添え、それ以外はcmの寸法のみ示す。
     */
    private function slideSizeLabel(int $cx, int $cy, bool $includeEmu = false): string
    {
        $detail = $this->sizeDetail($cx, $cy, $includeEmu);
        $ratio = $this->slideAspectRatioName($cx, $cy);

        return $ratio !== null ? "{$ratio}（{$detail}）" : $detail;
    }

    /**
     * 依頼BK-2: $includeEmuがtrueのときは、実際のEMU値も併記する
     * (cm表示だけでは両側が同じ文字列になるケースの解消)。
     */
    private function sizeDetail(int $cx, int $cy, bool $includeEmu): string
    {
        $cm = $this->cmLabel($cx, $cy);

        return $includeEmu ? sprintf('%s / %d × %d EMU', $cm, $cx, $cy) : $cm;
    }

    private function cmLabel(int $cx, int $cy): string
    {
        return sprintf('%.2f × %.2f cm', $cx / 360000, $cy / 360000);
    }

    /**
     * 依頼BL-1: 「既知サイズとの一致」ではなく「cx/cyの比そのもの」で
     * 判定する。従来(依頼BK-2)は絶対サイズの表だけを見ていたため、同じ
     * 16:9でも別のインチ数の資料(例: 9144000×5143500)に比率名が
     * 付かなかった。判定は寸法の一致(assertSlideSize())とは独立
     * ―― 比率が合っていても寸法が違えば差し込みは弾く(依頼者指定、
     * 比較スライドは絶対座標で置かれているため)。許容差は
     * admin_comparison_pptx.aspect_ratio_toleranceを使う
     * (slide_size_tolerance_emuとは別の設定、意味が異なるため兼用しない)。
     */
    private function slideAspectRatioName(int $cx, int $cy): ?string
    {
        if ($cy === 0) {
            return null;
        }

        $tolerance = (float) config('admin_comparison_pptx.aspect_ratio_tolerance');
        $ratio = $cx / $cy;

        foreach (self::KNOWN_ASPECT_RATIOS as $name => $knownRatio) {
            if (abs($ratio - $knownRatio) <= $tolerance) {
                return $name;
            }
        }

        return null;
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
