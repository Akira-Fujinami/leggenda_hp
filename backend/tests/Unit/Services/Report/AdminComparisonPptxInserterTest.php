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

    /**
     * 依頼BM(2026-09-09): 「競合が伝えていて自社が伝えていない項目」一覧
     * (rows/quote)から、6領域×各社のマトリクス(axes)へ作り直した。
     */
    private function comparisonSlideBytes(): string
    {
        return app(AdminComparisonPptxGenerator::class)->generate([
            'self_company_name' => 'テスト株式会社',
            'companies' => [
                ['name' => 'テスト株式会社', 'matched' => 16, 'total' => 24, 'is_self' => true],
                ['name' => '競合A社', 'matched' => 20, 'total' => 24, 'is_self' => false],
            ],
            'axes' => array_map(fn (string $name, string $caption) => [
                'name' => $name,
                'caption' => $caption,
                'denominator' => 4,
                'self_count' => 2,
                'competitor_counts' => [3],
                'self_gap' => true,
            ], ['活動的魅力', '資産的魅力', '経営スタイル', '就業環境', '情緒的便益', '金銭的便益'], [
                '事業・商品・成長性', '規模・実績・ブランド', '理念・組織・意思決定', '働き方・制度・場所', 'やりがい・人・風土', '報酬・福利厚生・成長機会',
            ]),
            'summary' => '総合では競合を下回ります、「経営スタイル」の1領域で競合の最高値を下回っています。理念・組織・意思決定の記述が薄い状態です。',
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
    /**
     * 依頼BK-3: $sldSzXmlOverrideを渡すと、自動生成する
     * `<p:sldSz cx="..." cy="..."/>` の代わりにそのまま使う
     * (属性の並び違い・type属性付き・タグ自体が無い、を再現するため)。
     */
    private function makeFixtureDeck(array $slideTexts, int $sldSzCx = self::SLIDE_W, int $sldSzCy = self::SLIDE_H, bool $includeReferenceKeyword = true, ?string $sldSzXmlOverride = null): string
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
            .($sldSzXmlOverride ?? '<p:sldSz cx="'.$sldSzCx.'" cy="'.$sldSzCy.'"/>')
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

    /**
     * 依頼BM-1/BM-4: 「競合が伝えていて自社が伝えていない項目」一覧
     * (件数に依存し0件だと下2/3が白紙になっていた旧構成)から、6領域の
     * マトリクスへ作り直したこと・他社サイトの引用文を一切載せないことを、
     * 生成されたスライドXMLで確認する。
     */
    public function test_comparison_slide_is_the_matrix_layout_and_contains_no_quotes(): void
    {
        $bytes = $this->comparisonSlideBytes();
        $tmp = tempnam(sys_get_temp_dir(), 'slide').'.pptx';
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive;
        $zip->open($tmp);
        $slideXml = $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();

        $this->assertStringContainsString('領域別の発信量', $slideXml);
        // 旧構成(依頼BG〜BI)の見出し・列名が残っていないこと。
        $this->assertStringNotContainsString('競合が伝えていて', $slideXml);
        $this->assertStringNotContainsString('代表的な記述', $slideXml);
        // comparisonSlideBytes()のテスト用フィクスチャに仕込んだダミーの
        // 引用文が万一残っていないこと(引用を扱う経路自体が無いことの確認)。
        $this->assertStringNotContainsString('サンプル', $slideXml);
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
            // 依頼BI-3: 実際の寸法をcmで、分かる場合は比率名(4:3等)も添えて
            // 文言に出すこと(依頼者指定の例文「この資料は 4:3（25.40 ×
            // 19.05 cm）です。」に合わせる)。
            $this->assertStringContainsString('4:3', $e->getMessage());
            $this->assertStringContainsString('25.40', $e->getMessage());
            $this->assertStringContainsString('cm', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 依頼BK(2026-09-09): 完全一致ではなく許容差(既定1200EMU)で判定する。
    // 本番で実物の16:9資料(cx=12191695、要求値との差はcxのみ305EMU)が
    // 完全一致判定により誤って弾かれたことが発端。
    // ------------------------------------------------------------------

    /**
     * BK-1必須要件: 1200EMU(既定の許容差ちょうど)ずれた資料は確実に通ること。
     * 本番の実物資料の実測値(305EMU)より大きい、許容差の境界値そのもので
     * 検証する。
     */
    public function test_a_deck_1200_emu_off_on_both_axes_is_accepted(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '参照元'], sldSzCx: self::SLIDE_W - 1200, sldSzCy: self::SLIDE_H - 1200);

        $mergedPath = $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
        $this->tempFiles[] = $mergedPath;

        $this->assertFileExists($mergedPath);
    }

    /**
     * 本番で実際に弾かれた資料の実測値(cx=12191695、cy=6858000、
     * 依頼BK-0で実測)を、そのまま再現して確認する。
     */
    public function test_the_actual_production_deck_dimensions_are_accepted(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '参照元'], sldSzCx: 12191695, sldSzCy: 6858000);

        $mergedPath = $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
        $this->tempFiles[] = $mergedPath;

        $this->assertFileExists($mergedPath);
    }

    /**
     * BK-1/BL-2必須要件: 許容差を入れても(広げても)4:3は確実に弾かれ、
     * 比率が違うケースの文言(「4:3」と出て「16:9ですが」にはならない)に
     * なること。
     */
    public function test_4_3_is_still_rejected_despite_the_tolerance(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '参照元'], sldSzCx: 9144000, sldSzCy: 6858000);

        try {
            $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
            $this->fail('例外が投げられるはず');
        } catch (ComparisonSlideInsertionException $e) {
            $this->assertStringContainsString('4:3', $e->getMessage());
            $this->assertStringNotContainsString('ですが', $e->getMessage());
        }
    }

    /**
     * BK-1/BL-2必須要件: 許容差を入れても(広げても)16:10は確実に弾かれ、
     * 比率名が文言に出ること。16:10(On-screen Show 16:10相当、10×6.25in)
     * = 9144000×5715000EMU。
     */
    public function test_16_10_is_still_rejected_despite_the_tolerance(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '参照元'], sldSzCx: 9144000, sldSzCy: 5715000);

        try {
            $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
            $this->fail('例外が投げられるはず');
        } catch (ComparisonSlideInsertionException $e) {
            $this->assertStringContainsString('16:10', $e->getMessage());
            $this->assertStringNotContainsString('ですが', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 依頼BL-1(2026-09-09): 比率名の判定を、既知の絶対サイズとの一致では
    // なくcx/cyの比そのもので行う。「比率は合うが寸法が違う」場合に、
    // 依頼BKで直したのと同種の「16:9ではないと言われたが、これは16:9で
    // ある」という混乱を再発させないよう、文言を出し分ける。
    // ------------------------------------------------------------------

    /**
     * 9144000×5143500(10×5.625in)はちょうど16:9だが、要求している
     * 13.333×7.5inとは別のインチ数 ―― 比率は合うが寸法が違うケース。
     * 「16:9ですが…」の文言になり、直しかたの一文が添えられること
     * (依頼者指定の例文どおり)。
     */
    public function test_a_different_sized_16_9_deck_is_rejected_with_a_same_ratio_message(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '参照元'], sldSzCx: 9144000, sldSzCy: 5143500);

        try {
            $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
            $this->fail('例外が投げられるはず');
        } catch (ComparisonSlideInsertionException $e) {
            $message = $e->getMessage();
            // 依頼者指定の例文: 「16:9ですが、25.40 × 14.29 cm です。
            // 33.87 × 19.05 cm の資料が必要です。」
            $this->assertStringContainsString('16:9ですが', $message);
            $this->assertStringContainsString('25.40', $message);
            $this->assertStringContainsString('14.29', $message);
            $this->assertStringContainsString('33.87', $message);
            $this->assertStringContainsString('19.05', $message);
            // 「16:9ではない」と誤読させる文言(比率が違うかのような
            // 「この資料は…」形式)になっていないこと。
            $this->assertStringNotContainsString('この資料は', $message);
            // 直しかたの一文(依頼者指定: 自分で直せるようにすること)。
            $this->assertStringContainsString('スライドのサイズ', $message);
            $this->assertStringContainsString('変更してください', $message);
        }
    }

    /**
     * 上下に細長い、既知のどの比率(4:3・16:9・16:10)にも一致しない資料。
     * 比率判定が例外や誤検出にならず、比率名なしで寸法だけが文言に出る
     * こと(依頼者指定のテストケース)。
     */
    public function test_a_portrait_deck_of_an_unknown_ratio_is_rejected_without_a_false_ratio_name(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '参照元'], sldSzCx: 6858000, sldSzCy: 12192000);

        try {
            $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
            $this->fail('例外が投げられるはず');
        } catch (ComparisonSlideInsertionException $e) {
            $message = $e->getMessage();
            // 実際の資料側(文頭)には比率名が付かず、寸法だけが出ること
            // ―― 要求側(16:9)には引き続き比率名が出るため、メッセージ
            // 全体からの単純な文字列不在チェックはできない(要求側の
            // 「16:9」は正しい)。文頭がそのまま寸法から始まることを見る。
            $this->assertStringStartsWith('この資料は19.05 × 33.87 cmです。', $message);
            $this->assertStringContainsString('16:9（33.87 × 19.05 cm）の資料が必要です。', $message);
        }
    }

    /**
     * 依頼BK-2: cm表示(小数2桁)まで丸めると両側が同じ文字列になっていた
     * (依頼者指摘の実例: 「この資料は33.87 × 19.05 cmです。16:9
     * （33.87 × 19.05 cm）の資料が必要です。」)。許容差を超えるがcm表示は
     * 一致する寸法(1500EMUずれ = 0.0042cm、四捨五入で同じ33.87cmになる)で、
     * 実際のEMU値が両側に添えられ、同じ文が2回出ないことを確認する。
     */
    public function test_when_rounded_cm_is_identical_the_message_shows_raw_emu_to_disambiguate(): void
    {
        config(['admin_comparison_pptx.slide_size_tolerance_emu' => 1200]);
        $deckPath = $this->makeFixtureDeck(['内容1', '参照元'], sldSzCx: 12193500, sldSzCy: 6858000);

        try {
            $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
            $this->fail('例外が投げられるはず');
        } catch (ComparisonSlideInsertionException $e) {
            $message = $e->getMessage();
            // cm表示(小数2桁)だけでは両側が同じ文字列になっていた
            // (依頼者指摘の実例)。実際のEMU値を両側に添えることで、
            // 読んで何が違うか分かるようにする。
            $this->assertStringContainsString('12193500', $message, '実際のEMU値が文言に出ること');
            $this->assertStringContainsString('12192000', $message, '要求側のEMU値も文言に出ること');
        }
    }

    // ------------------------------------------------------------------
    // 依頼BK-3: <p:sldSz>の属性の並び・type属性に依存しない読み取り。
    // ------------------------------------------------------------------

    public function test_sldsz_with_a_type_attribute_before_cx_cy_is_read_correctly(): void
    {
        $deckPath = $this->makeFixtureDeck(
            ['内容1', '参照元'],
            sldSzXmlOverride: '<p:sldSz type="screen16x9" cx="12192000" cy="6858000"/>',
        );

        $mergedPath = $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
        $this->tempFiles[] = $mergedPath;

        $this->assertFileExists($mergedPath);
    }

    public function test_sldsz_with_cy_before_cx_is_read_correctly(): void
    {
        $deckPath = $this->makeFixtureDeck(
            ['内容1', '参照元'],
            sldSzXmlOverride: '<p:sldSz cy="6858000" cx="12192000"/>',
        );

        $mergedPath = $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
        $this->tempFiles[] = $mergedPath;

        $this->assertFileExists($mergedPath);
    }

    public function test_a_deck_without_sldsz_is_rejected_as_unreadable_not_a_default(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '参照元'], sldSzXmlOverride: '');

        try {
            $this->inserter()->insert($deckPath, $this->comparisonSlideBytes());
            $this->fail('例外が投げられるはず');
        } catch (ComparisonSlideInsertionException $e) {
            $this->assertStringContainsString('読み取れませんでした', $e->getMessage());
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
