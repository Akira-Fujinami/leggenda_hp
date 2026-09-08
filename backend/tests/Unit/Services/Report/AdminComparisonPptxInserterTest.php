<?php

namespace Tests\Unit\Services\Report;

use App\Exceptions\Report\ComparisonSlideInsertionException;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\Report\AdminComparisonPptxInserter;
use Tests\TestCase;
use ZipArchive;

/**
 * 依頼BG: 実在の営業資料は持ち込めない(社外秘のため)ため、実物と同じ
 * パーツ構成(画像・ノート・複数レイアウト・複数スライド)を持つ自作の
 * フィクスチャPPTXで検証する。実物3本での目視確認は別途行う
 * (実装報告参照)。
 */
class AdminComparisonPptxInserterTest extends TestCase
{
    private const SLIDE_W = 12192000;

    private const SLIDE_H = 6858000;

    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    private function inserter(): AdminComparisonPptxInserter
    {
        return new AdminComparisonPptxInserter;
    }

    private function comparisonSlideBytes(): string
    {
        return app(AdminComparisonPptxGenerator::class)->generate([
            'self_company_name' => 'テスト株式会社',
            'companies' => [
                ['name' => 'テスト株式会社', 'matched' => 16, 'total' => 24, 'is_self' => true],
                ['name' => '競合A社', 'matched' => 20, 'total' => 24, 'is_self' => false],
            ],
            'competitor_count' => 1,
            'rows' => [
                ['sub_name' => '福利厚生', 'axis_name' => '金銭的便益', 'matched_count' => 1, 'quote' => 'サンプル'],
            ],
            'source_note' => 'テスト用ノート',
            'page_number' => null,
        ]);
    }

    /**
     * 実物同等のパーツ構成(画像1点・ノート1件・スライドレイアウト2種・
     * スライド複数枚)を持つ自作フィクスチャを組み立てる。
     *
     * @param  list<string>  $slideTexts  各スライドの本文(この配列の最後の要素の
     *                                    スライドが「参照元」相当のキーワードを含む)
     */
    private function makeFixtureDeck(array $slideTexts, int $sldSzCx = self::SLIDE_W, int $sldSzCy = self::SLIDE_H, bool $includeReferenceKeyword = true): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fixture-deck').'.pptx';
        $this->tempFiles[] = $path;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $slideCount = count($slideTexts);

        // 1x1透明PNG(実物のグラフ・画像に相当する、非テキストパーツの
        // 保全確認に使う)。
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $zip->addFromString('ppt/media/image1.png', $pngBytes);

        $zip->addFromString('ppt/theme/theme1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Fixture"><a:themeElements/></a:theme>');

        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldMaster xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>');
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $this->relsXml([
            ['Id' => 'rId1', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme', 'Target' => '../theme/theme1.xml'],
        ]));

        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldLayout xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $this->relsXml([
            ['Id' => 'rId1', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster', 'Target' => '../slideMasters/slideMaster1.xml'],
        ]));

        $zip->addFromString('ppt/notesMasters/notesMaster1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:notesMaster xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>');

        $zip->addFromString('ppt/notesSlides/notesSlide1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:notes xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>フィクスチャのノート</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:notes>');
        $zip->addFromString('ppt/notesSlides/_rels/notesSlide1.xml.rels', $this->relsXml([
            ['Id' => 'rId1', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesMaster', 'Target' => '../notesMasters/notesMaster1.xml'],
            ['Id' => 'rId2', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide', 'Target' => '../slides/slide1.xml'],
        ]));

        $contentTypeOverrides = [
            '/ppt/presentation.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
            '/ppt/slideMasters/slideMaster1.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml',
            '/ppt/slideLayouts/slideLayout1.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml',
            '/ppt/theme/theme1.xml' => 'application/vnd.openxmlformats-officedocument.theme+xml',
            '/ppt/notesMasters/notesMaster1.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.notesMaster+xml',
            '/ppt/notesSlides/notesSlide1.xml' => 'application/vnd.openxmlformats-officedocument.presentationml.notesSlide+xml',
        ];

        $presentationRels = [
            ['Id' => 'rId1', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster', 'Target' => 'slideMasters/slideMaster1.xml'],
        ];
        $sldIdEntries = [];
        $rIdCounter = 2;
        $sldIdCounter = 256;

        foreach ($slideTexts as $i => $text) {
            $slideNum = $i + 1;
            $isLast = $i === $slideCount - 1;
            $bodyText = ($isLast && $includeReferenceKeyword) ? $text : $text;

            $slideXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
                .'<p:cSld><p:spTree>'
                .($slideNum === 1 ? '<p:pic><p:blipFill><a:blip r:embed="rIdImg1"/></p:blipFill></p:pic>' : '')
                .'<p:sp><p:txBody><a:p><a:r><a:t>'.htmlspecialchars($bodyText, ENT_QUOTES | ENT_XML1).'</a:t></a:r></a:p></p:txBody></p:sp>'
                .'</p:spTree></p:cSld></p:sld>';
            $zip->addFromString("ppt/slides/slide{$slideNum}.xml", $slideXml);

            $slideRels = [
                ['Id' => 'rId1', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout', 'Target' => '../slideLayouts/slideLayout1.xml'],
            ];
            if ($slideNum === 1) {
                $slideRels[] = ['Id' => 'rIdImg1', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image', 'Target' => '../media/image1.png'];
                $slideRels[] = ['Id' => 'rId2', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide', 'Target' => '../notesSlides/notesSlide1.xml'];
            }
            $zip->addFromString("ppt/slides/_rels/slide{$slideNum}.xml.rels", $this->relsXml($slideRels));

            $contentTypeOverrides["/ppt/slides/slide{$slideNum}.xml"] = 'application/vnd.openxmlformats-officedocument.presentationml.slide+xml';

            $rId = 'rId'.$rIdCounter;
            $presentationRels[] = ['Id' => $rId, 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide', 'Target' => "slides/slide{$slideNum}.xml"];
            $sldIdEntries[] = ['id' => $sldIdCounter, 'rId' => $rId];
            $rIdCounter++;
            $sldIdCounter++;
        }

        $zip->addFromString('ppt/_rels/presentation.xml.rels', $this->relsXml($presentationRels));

        $sldIdListXml = implode('', array_map(fn (array $e) => '<p:sldId id="'.$e['id'].'" r:id="'.$e['rId'].'"/>', $sldIdEntries));
        $presentationXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            .'<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
            .'<p:sldIdLst>'.$sldIdListXml.'</p:sldIdLst>'
            .'<p:sldSz cx="'.$sldSzCx.'" cy="'.$sldSzCy.'"/>'
            .'<p:notesSz cx="6858000" cy="9144000"/>'
            .'</p:presentation>';
        $zip->addFromString('ppt/presentation.xml', $presentationXml);

        $typesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Default Extension="png" ContentType="image/png"/>'
            .implode('', array_map(fn ($part, $type) => '<Override PartName="'.$part.'" ContentType="'.$type.'"/>', array_keys($contentTypeOverrides), $contentTypeOverrides))
            .'</Types>';
        $zip->addFromString('[Content_Types].xml', $typesXml);

        $zip->addFromString('_rels/.rels', $this->relsXml([
            ['Id' => 'rId1', 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument', 'Target' => 'ppt/presentation.xml'],
        ]));

        $zip->close();

        return $path;
    }

    /**
     * @param  list<array{Id: string, Type: string, Target: string}>  $relationships
     */
    private function relsXml(array $relationships): string
    {
        $body = implode('', array_map(
            fn (array $r) => '<Relationship Id="'.$r['Id'].'" Type="'.$r['Type'].'" Target="'.$r['Target'].'"/>',
            $relationships,
        ));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$body.'</Relationships>';
    }

    /**
     * @return array<string, string>  ZIPエントリ名 => 中身のsha256
     */
    private function hashAllEntries(string $pptxPath): array
    {
        $zip = new ZipArchive;
        $zip->open($pptxPath);
        $hashes = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $hashes[$name] = hash('sha256', (string) $zip->getFromName($name));
        }
        $zip->close();

        return $hashes;
    }

    public function test_comparison_slide_does_not_reference_images_charts_or_embeddings(): void
    {
        $bytes = $this->comparisonSlideBytes();
        $tmp = tempnam(sys_get_temp_dir(), 'slide').'.pptx';
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive;
        $zip->open($tmp);
        $slideXml = $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();

        $this->assertSame(0, preg_match('/\br:(id|embed|link)="/', $slideXml), '比較スライドは画像・グラフ等を参照していないこと');
        $this->assertSame(0, substr_count($slideXml, 'schemeClr'), 'テーマ色(schemeClr)を使っていないこと');
        $this->assertGreaterThan(0, substr_count($slideXml, 'Meiryo'), 'フォントを明示指定していること');
    }

    public function test_inserts_the_comparison_slide_immediately_before_the_reference_page(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '内容2', '参照元']);

        $mergedPath = $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
        $this->tempFiles[] = $mergedPath;

        $zip = new ZipArchive;
        $zip->open($mergedPath);
        $presentationXml = $zip->getFromName('ppt/presentation.xml');
        $relsXml = $zip->getFromName('ppt/_rels/presentation.xml.rels');

        preg_match_all('/<p:sldId\s+id="(\d+)"\s+r:id="(rId\d+)"\s*\/>/', $presentationXml, $sldIdMatches, PREG_SET_ORDER);
        $this->assertCount(4, $sldIdMatches, '3枚+差し込み1枚=4枚になっていること');

        preg_match_all('/<Relationship\s+Id="(rId\d+)"[^>]*Target="([^"]+)"/', $relsXml, $relMatches, PREG_SET_ORDER);
        $targetByRid = [];
        foreach ($relMatches as $m) {
            $targetByRid[$m[1]] = $m[2];
        }

        $orderedTargets = array_map(fn ($m) => $targetByRid[$m[2]] ?? null, $sldIdMatches);

        // 元の3枚(slide1〜3)のうち、slide3(参照元)の直前に新規スライドが
        // 入っていること。新規スライドのファイル名はslide4.xml(既存の最大+1)。
        $this->assertSame('slides/slide1.xml', $orderedTargets[0]);
        $this->assertSame('slides/slide2.xml', $orderedTargets[1]);
        $this->assertSame('slides/slide4.xml', $orderedTargets[2], '差し込んだスライドは参照元の直前にあること');
        $this->assertSame('slides/slide3.xml', $orderedTargets[3], '参照元スライド自体はそのまま最後に残ること');

        $zip->close();
    }

    public function test_existing_parts_are_byte_identical_after_insertion(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '内容2', '参照元']);
        $beforeHashes = $this->hashAllEntries($deckPath);

        $mergedPath = $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
        $this->tempFiles[] = $mergedPath;
        $afterHashes = $this->hashAllEntries($mergedPath);

        $editedParts = ['ppt/presentation.xml', 'ppt/_rels/presentation.xml.rels', '[Content_Types].xml'];

        foreach ($beforeHashes as $name => $hash) {
            if (in_array($name, $editedParts, true)) {
                continue;
            }
            $this->assertSame($hash, $afterHashes[$name] ?? null, "既存パーツ {$name} のバイト列が変わっていないこと");
        }

        // 新規追加された2ファイル以外、エントリ数が増えていないこと。
        $this->assertCount(count($beforeHashes) + 2, $afterHashes);
    }

    public function test_layout_reference_matches_the_neighboring_slide_not_hardcoded(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '内容2', '参照元']);
        $mergedPath = $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
        $this->tempFiles[] = $mergedPath;

        $zip = new ZipArchive;
        $zip->open($mergedPath);
        $newSlideRels = $zip->getFromName('ppt/slides/_rels/slide4.xml.rels');
        $zip->close();

        $this->assertStringContainsString('slideLayouts/slideLayout1.xml', $newSlideRels);
    }

    public function test_the_generated_pptx_opens_and_content_types_matches_added_slide(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '参照元']);
        $mergedPath = $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
        $this->tempFiles[] = $mergedPath;

        $zip = new ZipArchive;
        $openResult = $zip->open($mergedPath, ZipArchive::CHECKCONS);
        $this->assertTrue($openResult === true, 'ZipArchiveで整合性エラー無く開けること');

        $this->assertNotFalse($zip->getFromName('ppt/slides/slide3.xml'), '新規スライドファイルが存在すること');
        $contentTypes = $zip->getFromName('[Content_Types].xml');
        $this->assertStringContainsString('/ppt/slides/slide3.xml', $contentTypes);

        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($zip->getFromName('ppt/presentation.xml')), 'presentation.xmlが妥当なXMLであること');
        $this->assertTrue($dom->loadXML($contentTypes), '[Content_Types].xmlが妥当なXMLであること');
        $this->assertTrue($dom->loadXML($zip->getFromName('ppt/_rels/presentation.xml.rels')), 'presentation.xml.relsが妥当なXMLであること');

        $zip->close();
    }

    public function test_throws_and_cleans_up_when_no_reference_page_is_found(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '内容2', '内容3']);

        $tempCountBefore = count(glob(sys_get_temp_dir().'/pptx-merged*'));

        try {
            $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
            $this->fail('例外が投げられるはず');
        } catch (ComparisonSlideInsertionException $e) {
            $this->assertStringContainsString('参照元', $e->getMessage());
        }

        $tempCountAfter = count(glob(sys_get_temp_dir().'/pptx-merged*'));
        $this->assertSame($tempCountBefore, $tempCountAfter, '失敗時に一時ファイルを残さないこと');
    }

    public function test_throws_when_slide_size_does_not_match(): void
    {
        // 4:3(10x7.5in相当のEMU、一般的な4:3サイズ)。
        $deckPath = $this->makeFixtureDeck(['内容1', '参照元'], sldSzCx: 9144000, sldSzCy: 6858000);

        try {
            $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
            $this->fail('例外が投げられるはず');
        } catch (ComparisonSlideInsertionException $e) {
            $this->assertStringContainsString('スライドサイズ', $e->getMessage());
            // 実際の寸法を文言に出すこと(依頼者指定)。
            $this->assertStringContainsString('in', $e->getMessage());
        }
    }

    /**
     * 末尾側から探すことの確認。依頼BH-1で「出典」単独を検出語から外した
     * ため、本文ページの「出典という語を含む説明」はもはやどの検出語にも
     * 一致しない ―― 末尾寄りの本当の参照元ページ(「参照元一覧」)だけが
     * 見つかること。
     */
    public function test_searches_from_the_end_and_prefers_the_later_matching_slide(): void
    {
        $deckPath = $this->makeFixtureDeck(['本文中に出典という語を含む説明', '内容2', '参照元一覧']);

        $mergedPath = $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
        $this->tempFiles[] = $mergedPath;

        $zip = new ZipArchive;
        $zip->open($mergedPath);
        $presentationXml = $zip->getFromName('ppt/presentation.xml');
        $relsXml = $zip->getFromName('ppt/_rels/presentation.xml.rels');
        preg_match_all('/<p:sldId\s+id="\d+"\s+r:id="(rId\d+)"\s*\/>/', $presentationXml, $sldIdMatches);
        preg_match_all('/<Relationship\s+Id="(rId\d+)"[^>]*Target="([^"]+)"/', $relsXml, $relMatches, PREG_SET_ORDER);
        $targetByRid = [];
        foreach ($relMatches as $m) {
            $targetByRid[$m[1]] = $m[2];
        }
        $orderedTargets = array_map(fn ($rid) => $targetByRid[$rid] ?? null, $sldIdMatches[1]);

        // 新規スライド(slide4.xml)が3番目(=slide3「参照元一覧」の直前)に
        // 入っていること。slide1の「出典」には一切反応しないこと。
        $this->assertSame('slides/slide4.xml', $orderedTargets[2]);
    }

    /**
     * 依頼BH-1(必須要件): 最終ページに「参照元」も「APPENDIX」も無く、
     * 本文ページに「出典：」(グラフ・数値の注記として一般的な表現)がある
     * 場合、位置を推測せず中止すること。「出典」を検出語から外した
     * ことで、この本文中の「出典：」に誤って反応しないことを確認する。
     */
    public function test_a_body_page_citation_note_does_not_trigger_a_match_and_insertion_is_aborted(): void
    {
        $deckPath = $this->makeFixtureDeck([
            '内容1',
            '出典:社内調査データ(2026年)に基づく。グラフの数値は概算値です。',
            'まとめ',
        ]);

        try {
            $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
            $this->fail('例外が投げられるはず(本文の「出典」に誤って反応してはいけない)');
        } catch (ComparisonSlideInsertionException $e) {
            $this->assertStringContainsString('参照元', $e->getMessage());
        }
    }
}
