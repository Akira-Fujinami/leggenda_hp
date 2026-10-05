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
     * 依頼BS-3(2026-09-11): tempnam()が拡張子無しで予約した実体を、
     * rename()でそのまま最終パスとして使い回す(依頼BJ-3/BR-3と同じ方式)。
     * `tempnam(...).'.ext'`のように別パスへ書き込むと、tempnam()自身が
     * 作った拡張子無しの実体が/tmpに残り続ける。
     */
    private function reservedTempPath(string $prefix, string $extension): string
    {
        $reserved = tempnam(sys_get_temp_dir(), $prefix);
        $path = $reserved.'.'.$extension;
        rename($reserved, $path);

        return $path;
    }

    /**
     * 依頼CB-4(2026-09-24): AdminComparisonPptxDataBuilderが返す形と同じ
     * $dataフィクスチャ。generate()(CB-1)・generateMissingItemsSlide()
     * (CB-2)・generateSiteHierarchySlide()(CB-3)がいずれもこれを使う
     * ―― 4枚が同じ会社・データについて話していることを保証するため。
     */
    private function comparisonData(): array
    {
        return [
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
            'missing_items' => [
                'heading' => '競合が伝えていて、自社が伝えていない項目',
                'empty_text' => '競合と比べて、自社に不足している項目は見つかりませんでした。',
                'items' => [
                    ['axis_name' => '経営スタイル', 'sub_name' => 'リーダーシップ', 'region' => '会社との距離', 'impact' => '経営者・幹部の考え方や意思決定スタイルについての記述。自社サイトでは確認できていません。', 'candidate_survey' => ['item' => '代表・経営層のインタビュー', 'percentage' => 13.4]],
                ],
                'others_count' => 0,
            ],
            'self_readable' => true,
            'self_material_sufficient' => true,
            'survey_comparison' => [
                'rows' => [
                    ['rank' => 1, 'key' => 'job_content', 'name' => '希望するポジションの仕事・業務内容', 'percentage' => 24.6, 'self_state' => 'unconfirmed', 'mapped_count' => 1, 'self_matched_count' => 0, 'competitor_count' => 1],
                    ['rank' => 2, 'key' => 'career_path', 'name' => '実現できるキャリアパス', 'percentage' => 23.8, 'self_state' => 'confirmed', 'mapped_count' => 1, 'self_matched_count' => 1, 'competitor_count' => 1],
                ],
                'competitor_total' => 1,
                'excluded_competitor_count' => 0,
            ],
            'candidate_survey_source_note' => (string) config('brand_wheel_candidate_survey.source_note'),
            'recommended_site_flow_names' => ['トップメッセージ'],
            'source_note' => 'テスト用ノート',
            'page_number' => null,
        ];
    }

    /**
     * 依頼CL-3(2026-10-05): AdminComparisonSiteHierarchyBuilder::buildTree()が
     * 返す木と同じ形のフィクスチャ。
     */
    private function hierarchyData(): array
    {
        return [
            'mode' => 'menu',
            'origin_url' => 'https://example.com/recruit/',
            'top' => ['url' => 'https://example.com/recruit/', 'title' => '採用情報', 'headings' => ['私たちの仕事'], 'menu_item_count' => 2],
            'branches' => [
                ['name' => '仕事を知る', 'url' => 'https://example.com/recruit/careers/', 'page_count' => 5, 'pages' => ['インタビュー01', 'インタビュー02'], 'other_page_count' => 0],
            ],
            'other_branch_count' => 0,
            'total_fetched_pages' => 8,
            'pages_within_origin' => 5,
            'outside_origin_count' => 3,
            'second_level_source' => ['links' => 1, 'url' => 0],
            'menu_source' => 'raw',
        ];
    }

    /**
     * 依頼BM(2026-09-09): 「競合が伝えていて自社が伝えていない項目」一覧
     * (rows/quote)から、6領域×各社のマトリクス(axes)へ作り直した。
     * 依頼CB-1(2026-09-24): さらにブランド・ホイール比較(ヘキサゴン)へ
     * 作り直した ―― まとめの帯・項目一覧はCB-2の専用スライドへ移した。
     */
    private function comparisonSlideBytes(): string
    {
        return app(AdminComparisonPptxGenerator::class)->generate($this->comparisonData());
    }

    /**
     * 依頼CB-2(2026-09-24): 「足りないもの」スライド。
     */
    private function missingItemsSlideBytes(): string
    {
        return app(AdminComparisonPptxGenerator::class)->generateMissingItemsSlide($this->comparisonData());
    }

    /**
     * 依頼CB-3(2026-09-24): 「自社サイトの階層図」スライド。
     */
    private function hierarchySlideBytes(): string
    {
        return app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide($this->comparisonData(), $this->hierarchyData());
    }

    /**
     * 依頼BZ-2: 差し込みは説明ページ→比較ページの2枚になった。
     */
    private function explanationSlideBytes(): string
    {
        return app(AdminComparisonPptxGenerator::class)->generateExplanationSlide();
    }

    /**
     * 依頼CB-4(2026-09-24): 差し込みは説明→比較(CB-1)→足りないもの(CB-2)→
     * 階層図(CB-3)の4枚になった。依頼CL-4(2026-10-05): 「求職者が知りたい
     * 情報と、自社サイト」(CL-2)を「足りないもの」の次に加えた5枚。
     *
     * @return list<string>  insert()に渡す順の配列
     */
    private function fiveSlideBytesList(): array
    {
        return [
            $this->explanationSlideBytes(),
            $this->comparisonSlideBytes(),
            $this->missingItemsSlideBytes(),
            app(AdminComparisonPptxGenerator::class)->generateSurveyComparisonSlide($this->comparisonData()),
            $this->hierarchySlideBytes(),
        ];
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
        $path = $this->reservedTempPath('fixture-deck', 'pptx');
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
        $tmp = $this->reservedTempPath('slide', 'pptx');
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
     * 依頼CB-1(2026-09-24): 比較スライドはブランド・ホイール比較
     * (ヘキサゴン、Shape\Lineのみ)+旧マトリクスを縮小した数値表。まとめの
     * 帯・「足りないもの」一覧はCB-2の専用スライドへ移ったため、この
     * スライドには出ないこと(取り違えて両方に描いていないかの確認)。
     */
    public function test_comparison_slide_is_the_wheel_layout_without_missing_items(): void
    {
        $bytes = $this->comparisonSlideBytes();
        $tmp = $this->reservedTempPath('slide', 'pptx');
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive;
        $zip->open($tmp);
        $slideXml = $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();

        $this->assertStringContainsString('ブランド・ホイール比較', $slideXml);
        $this->assertStringContainsString('領域別の発信量', $slideXml);
        // 依頼CB-1: ヘキサゴンの輪郭はp:cxnSp(Shape\Line)で描く。自社+競合1社
        // それぞれに[外周の参照六角形6本+データ六角形6本]=12本、計24本。
        $this->assertSame(24, substr_count($slideXml, '<p:cxnSp>'), 'ヘキサゴンの輪郭が2社×12本描かれていること');
        // CB-4で「足りないもの」専用スライドへ移った内容が、この比較
        // スライドには出ないこと。
        $this->assertStringNotContainsString('競合が伝えていて、自社が伝えていない項目', $slideXml);
        $this->assertStringNotContainsString('候補者調査', $slideXml);
    }

    /**
     * 依頼CC-1必須: 六角形の外側に6領域のラベルが出ること(自社のみ)。
     * ラベルはconfig('brand_wheel.axes.*.name_ja')から取り(直書きしない)、
     * 頂点の並び順が下の領域別数値表の行順と一致すること ―― 両方とも
     * generate()内の同じ$data['axes']をそのまま渡している
     * (AdminComparisonPptxGenerator::addWheelHexagons()/addMatrixSection())
     * ため、XML上での出現順(ラベルは表より先に描画される)で検証する。
     */
    public function test_wheel_axis_labels_are_present_and_their_order_matches_the_matrix_table_row_order(): void
    {
        $bytes = $this->comparisonSlideBytes();
        $tmp = $this->reservedTempPath('slide', 'pptx');
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive;
        $zip->open($tmp);
        $slideXml = $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();

        $axisNames = array_column((array) config('brand_wheel.axes'), 'name_ja');
        $this->assertCount(6, $axisNames);

        $firstOffsets = [];
        foreach ($axisNames as $name) {
            $this->assertStringContainsString($name, $slideXml, "軸名「{$name}」がラベルとして出ること");
            $firstOffsets[$name] = strpos($slideXml, $name);
        }

        // 頂点のラベルはヘキサゴン(addWheelHexagons())の中で、下の領域別
        // 数値表(addMatrixSection())より先に描画される。各軸名の最初の
        // 出現(=頂点ラベル)が、config('brand_wheel.axes')の順(=表の行順)
        // どおりに並んでいることを、XML中の出現位置で確認する。
        $sortedByOffset = $firstOffsets;
        asort($sortedByOffset);
        $this->assertSame($axisNames, array_keys($sortedByOffset), '頂点ラベルの並び順が領域別数値表の行順(config順)と一致していること');
    }

    /**
     * 依頼CB-1必須: 競合3社・4社・5社のいずれでも崩れないこと(実機画像化で
     * 3社を確認済み。ここではヘキサゴンの輪郭本数(自社+競合N社の
     * (N+1)社×12本)と外部参照ゼロで、4社・5社でも例外なく生成できることを
     * 確認する)。
     */
    public function test_wheel_comparison_slide_does_not_break_with_3_4_or_5_competitors(): void
    {
        foreach ([3, 4, 5] as $competitorCount) {
            $companies = [['name' => 'テスト株式会社', 'matched' => 12, 'total' => 24, 'is_self' => true]];
            $axes = array_map(fn (string $name) => [
                'name' => $name,
                'caption' => null,
                'denominator' => 4,
                'self_count' => 2,
                'competitor_counts' => array_fill(0, $competitorCount, 3),
                'self_gap' => true,
            ], ['活動的魅力', '資産的魅力', '経営スタイル', '就業環境', '情緒的便益', '金銭的便益']);
            for ($i = 0; $i < $competitorCount; $i++) {
                $companies[] = ['name' => "競合{$i}社", 'matched' => 18, 'total' => 24, 'is_self' => false];
            }

            $bytes = app(AdminComparisonPptxGenerator::class)->generate([
                'companies' => $companies,
                'axes' => $axes,
                'source_note' => 'テスト用ノート',
                'page_number' => null,
            ]);
            $tmp = $this->reservedTempPath('slide', 'pptx');
            $this->tempFiles[] = $tmp;
            file_put_contents($tmp, $bytes);

            $zip = new ZipArchive;
            $zip->open($tmp);
            $slideXml = $zip->getFromName('ppt/slides/slide1.xml');
            $zip->close();

            $this->assertSame(0, preg_match('/\br:(id|embed|link)="/', $slideXml), "競合{$competitorCount}社: 外部参照が無いこと");
            $this->assertSame(($competitorCount + 1) * 12, substr_count($slideXml, '<p:cxnSp>'), "競合{$competitorCount}社: ヘキサゴンの輪郭本数");
            foreach ($companies as $company) {
                $this->assertStringContainsString($company['name'], $slideXml, "競合{$competitorCount}社: 全社名が出ること");
            }
        }
    }

    /**
     * 依頼CB-2/CC-2: 「足りないもの」スライドの外部参照ゼロ・内容(冒頭の
     * 説明・項目名・領域タグ・一文・候補者調査の対応・出典)を確認する。
     */
    public function test_missing_items_slide_has_no_external_refs_and_shows_region_impact_and_survey(): void
    {
        $bytes = $this->missingItemsSlideBytes();
        $tmp = $this->reservedTempPath('slide', 'pptx');
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive;
        $zip->open($tmp);
        $slideXml = $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();

        $this->assertSame(0, preg_match('/\br:(id|embed|link)="/', $slideXml), '外部参照が無いこと');
        $this->assertSame(0, substr_count($slideXml, 'schemeClr'));
        $this->assertGreaterThan(0, substr_count($slideXml, 'Meiryo'));

        $this->assertStringContainsString('足りないもの', $slideXml);
        // 依頼CC-2②(必須): 何と何を突き合わせているかの説明が冒頭に出ること。
        $this->assertStringContainsString(
            htmlspecialchars((string) config('admin_comparison_pptx.missing_items_intro'), ENT_QUOTES | ENT_XML1),
            $slideXml,
        );
        $this->assertStringContainsString('競合が伝えていて、自社が伝えていない項目', $slideXml);
        $this->assertStringContainsString('リーダーシップ', $slideXml);
        $this->assertStringContainsString('会社との距離', $slideXml, '領域タグが出ること');
        $this->assertStringContainsString('経営者・幹部の考え方や意思決定スタイルについての記述。', $slideXml, '一文(定義文ベース)が出ること');
        $this->assertStringContainsString('代表・経営層のインタビュー', $slideXml, '候補者調査の対応項目名が出ること');
        $this->assertStringContainsString('13.4', $slideXml, '候補者調査の割合が出ること');
        // 依頼CC-2①②: 「〇〇%の求職者が求めているが、自社サイトでは
        // 確認できなかった」の順で因果が分かる文になっていること
        // (旧文言「候補者調査：「〇〇」を重視する求職者　N%」ではないこと)。
        $expectedSurveyText = sprintf((string) config('admin_comparison_pptx.missing_item_survey_template'), '代表・経営層のインタビュー', '13.4');
        $this->assertStringContainsString(htmlspecialchars($expectedSurveyText, ENT_QUOTES | ENT_XML1), $slideXml);
        // 出典(config('brand_wheel_candidate_survey.source_note'))が必ず
        // 出ること。
        $this->assertStringContainsString('OTOGI', $slideXml);
        $this->assertStringContainsString('prtimes.jp', $slideXml);
    }

    /**
     * 依頼CB-2必須: 0件のときにページが崩れない(空文字列や例外にならない)
     * こと。対応表に無い項目(candidate_survey.item===null)は割合欄を
     * 空にし、数字を捏造しないこと。
     */
    public function test_missing_items_slide_handles_zero_items_and_unmapped_candidate_survey(): void
    {
        $data = $this->comparisonData();
        $data['missing_items'] = [
            'heading' => '競合3社中2社以上が伝えていて、自社が伝えていない項目',
            'empty_text' => '見つかりませんでした。',
            'items' => [],
            'others_count' => 0,
        ];
        $bytes = app(AdminComparisonPptxGenerator::class)->generateMissingItemsSlide($data);
        $tmp = $this->reservedTempPath('slide', 'pptx');
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive;
        $zip->open($tmp);
        $slideXml = $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();

        $this->assertStringContainsString('見つかりませんでした。', $slideXml);

        // 対応表に無い項目(candidate_survey.item===null)で割合が空になること
        // (数字を捏造しない)。
        $data['missing_items']['items'] = [
            ['axis_name' => '情緒的便益', 'sub_name' => '優越感', 'region' => '仕事の魅力', 'impact' => 'ダミーの一文', 'candidate_survey' => ['item' => null, 'percentage' => null]],
        ];
        $bytes2 = app(AdminComparisonPptxGenerator::class)->generateMissingItemsSlide($data);
        file_put_contents($tmp, $bytes2);
        $zip->open($tmp);
        $slideXml2 = $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();

        // 依頼CC-2②: 「該当なし」の一言(重要でないという意味ではないことが
        // 伝わる文言、config('admin_comparison_pptx.missing_item_survey_none_text'))。
        $this->assertStringContainsString(
            htmlspecialchars((string) config('admin_comparison_pptx.missing_item_survey_none_text'), ENT_QUOTES | ENT_XML1),
            $slideXml2,
        );
        $this->assertStringNotContainsString('％', $slideXml2);
    }

    /**
     * 依頼CF追補(2026-09-30、必須修正): missing_item_impact_templateを
     * '%s'(定義文のみ)にしたこと(依頼CF-5①)で、候補者調査「該当なし」の
     * 行から「自社サイトでは確認できなかった」旨が消えていた不具合の
     * 再発防止。この行だけは、impact(定義)・survey_none_textの2つを
     * 合わせて読んでも「確認できなかった」旨と「重要でないという意味では
     * ない」旨の両方が残っていること。
     */
    public function test_missing_item_survey_none_text_still_conveys_not_confirmed_and_not_unimportant(): void
    {
        $noneText = (string) config('admin_comparison_pptx.missing_item_survey_none_text');

        $this->assertStringContainsString('確認でき', $noneText, '「自社サイトでは確認できなかった」旨が残っていること');
        $this->assertStringContainsString('重要でない', $noneText, '「重要でないという意味ではない」旨が残っていること');
    }

    /**
     * 依頼CB-2必須: 上限を超えたとき「ほかN件」が出ること。
     */
    public function test_missing_items_slide_shows_others_count_when_over_the_limit(): void
    {
        $data = $this->comparisonData();
        $data['missing_items']['items'] = [
            ['axis_name' => '経営スタイル', 'sub_name' => 'リーダーシップ', 'region' => '会社との距離', 'impact' => '一文', 'candidate_survey' => ['item' => null, 'percentage' => null]],
        ];
        $data['missing_items']['others_count'] = 5;
        $bytes = app(AdminComparisonPptxGenerator::class)->generateMissingItemsSlide($data);
        $tmp = $this->reservedTempPath('slide', 'pptx');
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive;
        $zip->open($tmp);
        $slideXml = $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();

        $this->assertStringContainsString('ほか5件', $slideXml);
    }

    /**
     * 依頼CL-3(2026-10-05): 「自社サイトの階層図」(木の形)スライドの外部参照ゼロ・
     * 内容(TOP・第1階層・第2階層・点線の枝・巡回範囲の注記)を確認する。
     * 「ありません」と断定する文言がどこにも無いこと(必須)。
     */
    public function test_hierarchy_slide_has_no_external_refs_and_never_asserts_nonexistence(): void
    {
        $bytes = $this->hierarchySlideBytes();
        $tmp = $this->reservedTempPath('slide', 'pptx');
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive;
        $zip->open($tmp);
        $slideXml = $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();

        $this->assertSame(0, preg_match('/\br:(id|embed|link)="/', $slideXml));
        $this->assertSame(0, substr_count($slideXml, 'schemeClr'));
        $this->assertGreaterThan(0, substr_count($slideXml, 'Meiryo'));

        $this->assertStringContainsString('自社サイトの階層図', $slideXml);
        $this->assertStringContainsString('https://example.com/recruit/', $slideXml);
        // TOP(ページ名・主な見出し・メニューの項目数)。
        $this->assertStringContainsString('採用情報', $slideXml);
        $this->assertStringContainsString('私たちの仕事', $slideXml);
        $this->assertStringContainsString('メニュー2項目', $slideXml);
        // 第1階層(メニューに書かれている文字)・ページ数・第2階層(ページ名)。
        $this->assertStringContainsString('仕事を知る', $slideXml);
        $this->assertStringContainsString('5ページ', $slideXml);
        $this->assertStringContainsString('インタビュー01', $slideXml);
        // 依頼CC-3①(必須): 「巡回したN件のうち起点URL配下はM件」の一文。
        $expectedScopeNote = sprintf((string) config('admin_comparison_pptx.site_hierarchy_scope_note'), 8, 5);
        $this->assertStringContainsString(htmlspecialchars($expectedScopeNote, ENT_QUOTES | ENT_XML1), $slideXml);
        $this->assertStringContainsString('追加を検討したい導線', $slideXml);
        $this->assertStringContainsString('トップメッセージ', $slideXml);
        $this->assertStringContainsString('巡回は最大', $slideXml, '巡回範囲の注記が出ること');
        $this->assertStringContainsString('50', $slideXml, 'config(brand_wheel.crawl_max_pages)の値が埋め込まれること');

        // 依頼CB-3必須: 「ありません」と断定する文言が無いこと。
        $this->assertStringNotContainsString('ありません', $slideXml);
        // 依頼CL-3: 「(ページ名未取得)」の表示は新しい形では使わない。
        $this->assertStringNotContainsString('ページ名未取得', $slideXml);
    }

    /**
     * 依頼CL-3: 「残りは同じドメインの別のセクションです。」は、起点URL配下でない
     * ページが1件以上あるときだけ出る(従来は50件すべてが配下でも出ていた)。
     */
    public function test_hierarchy_slide_shows_the_outside_sentence_only_when_some_pages_are_outside_the_origin(): void
    {
        $suffix = (string) config('admin_comparison_pptx.site_hierarchy_scope_outside_suffix');
        $this->assertNotSame('', $suffix);

        $withOutside = $this->hierarchyData();
        $withOutside['outside_origin_count'] = 3;
        $xmlWith = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide($this->comparisonData(), $withOutside));
        $this->assertStringContainsString(htmlspecialchars($suffix, ENT_QUOTES | ENT_XML1), $xmlWith);

        $allWithin = $this->hierarchyData();
        $allWithin['total_fetched_pages'] = 50;
        $allWithin['pages_within_origin'] = 50;
        $allWithin['outside_origin_count'] = 0;
        $xmlWithout = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide($this->comparisonData(), $allWithin));
        $this->assertStringNotContainsString(htmlspecialchars($suffix, ENT_QUOTES | ENT_XML1), $xmlWithout);
        $this->assertStringContainsString('巡回した50件のうち、この起点URL配下にあったのは50件でした。', $xmlWithout);
    }

    /**
     * 依頼CL-3: 木は直線(p:cxnSp)で描く。点線の枝は点線(prstDash=dash)、
     * 点線の枝が0件のときは点線も見出しも描かない。
     */
    public function test_hierarchy_slide_draws_the_tree_with_lines_and_dashed_recommendation_branches(): void
    {
        $xml = $this->slideXmlOf($this->hierarchySlideBytes());
        $this->assertGreaterThan(5, substr_count($xml, '<p:cxnSp>'), '幹・枝・括弧線が直線で描かれていること');
        $this->assertStringContainsString('prstDash val="dash"', $xml, '点線の枝が点線で描かれていること');

        $data = $this->comparisonData();
        $data['recommended_site_flow_names'] = [];
        $xmlNone = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide($data, $this->hierarchyData()));
        $this->assertStringNotContainsString('追加を検討したい導線', $xmlNone);
        $this->assertStringNotContainsString('prstDash val="dash"', $xmlNone);
    }

    /**
     * 点線の枝が上限(site_hierarchy_tree_recommended_limit)を超えたら「ほかN」に
     * まとめる。
     */
    public function test_hierarchy_slide_folds_recommendation_branches_over_the_limit(): void
    {
        $limit = (int) config('admin_comparison_pptx.site_hierarchy_tree_recommended_limit');
        $names = [];
        for ($i = 1; $i <= $limit + 3; $i++) {
            $names[] = "導線{$i}";
        }
        $data = $this->comparisonData();
        $data['recommended_site_flow_names'] = $names;
        $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide($data, $this->hierarchyData()));

        $this->assertStringContainsString("導線{$limit}", $xml);
        $this->assertStringNotContainsString('導線'.($limit + 1).'<', $xml);
        $this->assertStringContainsString('ほか3', $xml);
    }

    /**
     * 依頼CL-3: 木の作り方の説明が、メニュー版とURL階層版(代替)で切り替わる。
     * メニューの項目数は、メニューから作ったときだけTOPに添える。
     */
    public function test_hierarchy_slide_explains_how_the_tree_was_built_and_shows_the_menu_count_only_for_menu_mode(): void
    {
        $menuXml = $this->slideXmlOf($this->hierarchySlideBytes());
        $this->assertStringContainsString(htmlspecialchars((string) config('admin_comparison_pptx.site_hierarchy_tree_mode_note_menu'), ENT_QUOTES | ENT_XML1), $menuXml);
        $this->assertStringContainsString('メニュー2項目', $menuXml);

        $urlMode = $this->hierarchyData();
        $urlMode['mode'] = 'url';
        $urlMode['top']['menu_item_count'] = 0;
        $urlXml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide($this->comparisonData(), $urlMode));
        $this->assertStringContainsString(htmlspecialchars((string) config('admin_comparison_pptx.site_hierarchy_tree_mode_note_url'), ENT_QUOTES | ENT_XML1), $urlXml);
        $this->assertStringNotContainsString('メニュー0項目', $urlXml);
        $this->assertStringNotContainsString('メニュー2項目', $urlXml);
    }

    /**
     * 依頼CF-5②(2026-09-29): axis_unread_caveat(依頼BZ-1由来、「絶対に
     * 消してはいけない文言」)が、この階層図スライド(実質最後の内容
     * ページ)の末尾に出ること ―― 説明ページ(1枚目)からは移した
     * (AdminComparisonPptxExplanationSlideTest参照)。文言自体は
     * 変更していない。
     */
    public function test_hierarchy_slide_shows_the_axis_unread_caveat(): void
    {
        $slideXml = $this->slideXmlOf($this->hierarchySlideBytes());

        $caveat = (string) config('brand_wheel.axis_unread_caveat');
        $this->assertNotSame('', $caveat);
        $this->assertStringContainsString(htmlspecialchars($caveat, ENT_QUOTES | ENT_XML1), $slideXml);
    }

    /**
     * 依頼CF追補(2026-09-30、必須修正の再発防止)・依頼CL-3: 第1階層が上限いっぱい・
     * 各枝の第2階層が上限いっぱい(「ほかNページ」つき)・第1階層の「ほかN」・点線の枝が
     * 上限いっぱい(「ほかN」つき)が同時に最大になる、最も可変コンテンツが多い
     * ケースでも、axis_unread_caveatと巡回範囲の注記は必ず描かれ、固定位置の
     * 免責文(HIERARCHY_AXIS_CAVEAT_RULE_TOP_IN=5.75in)より上に木が収まること。
     */
    public function test_hierarchy_slide_shows_the_caveat_and_the_tree_stays_above_it_even_at_the_worst_case_load(): void
    {
        $firstLimit = (int) config('admin_comparison_pptx.site_hierarchy_tree_first_level_limit');
        $secondLimit = (int) config('admin_comparison_pptx.site_hierarchy_tree_second_level_limit');
        $recommendedLimit = (int) config('admin_comparison_pptx.site_hierarchy_tree_recommended_limit');

        $branches = [];
        for ($i = 0; $i < $firstLimit; $i++) {
            $pages = [];
            for ($k = 1; $k <= $secondLimit; $k++) {
                $pages[] = "ページ{$i}-{$k}";
            }
            $branches[] = ['name' => "セクション{$i}", 'url' => "https://example.com/recruit/s{$i}/", 'page_count' => 20 - $i, 'pages' => $pages, 'other_page_count' => 7];
        }
        $tree = $this->hierarchyData();
        $tree['branches'] = $branches;
        $tree['other_branch_count'] = 2;
        $tree['total_fetched_pages'] = 50;
        $tree['pages_within_origin'] = 40;
        $tree['outside_origin_count'] = 10;

        $data = $this->comparisonData();
        $data['recommended_site_flow_names'] = array_map(fn (int $i) => "導線{$i}", range(1, $recommendedLimit + 2));

        $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide($data, $tree));

        $this->assertStringContainsString('セクション'.($firstLimit - 1), $xml, '第1階層が上限いっぱい描かれていること(前提の確認)');
        $this->assertStringContainsString('ほか7ページ', $xml, '第2階層の「ほかNページ」が出ていること(前提の確認)');
        $this->assertStringContainsString('ほか2]]>', $xml, '第1階層の「ほかN」が出ていること(前提の確認)');
        $this->assertStringContainsString('導線'.$recommendedLimit, $xml, '点線の枝が上限いっぱい描かれていること(前提の確認)');

        $caveat = (string) config('brand_wheel.axis_unread_caveat');
        $this->assertStringContainsString(htmlspecialchars($caveat, ENT_QUOTES | ENT_XML1), $xml, '可変コンテンツが最大でも、免責文は必ず出ること');
        $this->assertStringContainsString('巡回は最大', $xml, '可変コンテンツが最大でも、巡回範囲の注記は必ず出ること');

        // 木(第1階層・第2階層・点線の枝のテキスト)の下端が、固定位置の免責文
        // (罫線5.75in)より上に収まっていること ―― 実機画像化の結果をコードでも
        // 守る(寸法やconfigの上限を変えたときに気づけるようにする)。
        $caveatTop = 5.75;
        foreach ($this->textBoxes($xml) as $box) {
            if (str_contains($box['text'], mb_substr($caveat, 0, 10)) || str_contains($box['text'], '巡回は最大') || $box['y'] >= $caveatTop) {
                continue;
            }
            $this->assertLessThanOrEqual($caveatTop + 0.005, $box['y'] + $box['h'], "「{$box['text']}」が免責文の固定位置より上に収まること");
        }
    }

    /**
     * 依頼CL-3: 巡回した範囲に枝が1件も無いとき、ページが崩れず(例外にならない)、
     * 「見つかりませんでした」の文言(「ありません」ではない)になる。TOPは描く。
     */
    public function test_hierarchy_slide_handles_zero_branches_without_asserting_nonexistence(): void
    {
        $tree = $this->hierarchyData();
        $tree['branches'] = [];
        $tree['top']['menu_item_count'] = 0;
        $tree['mode'] = 'url';
        $tree['total_fetched_pages'] = 0;
        $tree['pages_within_origin'] = 0;
        $tree['outside_origin_count'] = 0;
        $bytes = app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide($this->comparisonData(), $tree);
        $slideXml = $this->slideXmlOf($bytes);

        $this->assertStringContainsString('見つかりませんでした', $slideXml);
        $this->assertStringNotContainsString('ありません', $slideXml);
        $this->assertStringContainsString('https://example.com/recruit/', $slideXml);
    }

    /**
     * 枝に第2階層が1件も無い(0ページ)ときも崩れない。
     */
    public function test_hierarchy_slide_handles_a_branch_without_second_level_pages(): void
    {
        $tree = $this->hierarchyData();
        $tree['branches'] = [['name' => '採用の流れ', 'url' => 'https://example.com/recruit/flow/', 'page_count' => 0, 'pages' => [], 'other_page_count' => 0]];
        $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide($this->comparisonData(), $tree));

        $this->assertStringContainsString('採用の流れ', $xml);
        $this->assertStringContainsString('0ページ', $xml);
    }

    // ------------------------------------------------------------------
    // 依頼CL-1(2026-10-05): ブランド・ホイール比較の配置(横3列)。
    // 図形同士・図形と文字が重ならないことを、XMLの座標から確かめる
    // (実機画像化で見つけた崩れの再発防止。画像化そのものの代わりではない)。
    // ------------------------------------------------------------------

    /**
     * @return list<array{x: float, y: float, w: float, h: float, text: string}>  inch単位。文字を持つテキストボックスのみ。
     */
    private function textBoxes(string $slideXml): array
    {
        $dom = new \DOMDocument;
        $dom->loadXML($slideXml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

        $boxes = [];
        foreach ($xp->query('//p:sp[p:nvSpPr/p:cNvSpPr[@txBox="1"]]') as $sp) {
            $text = '';
            foreach ($xp->query('.//a:t', $sp) as $t) {
                $text .= $t->textContent;
            }
            if (trim($text) === '') {
                continue;
            }
            $off = $xp->query('./p:spPr/a:xfrm/a:off', $sp)->item(0);
            $ext = $xp->query('./p:spPr/a:xfrm/a:ext', $sp)->item(0);
            $boxes[] = [
                'x' => (int) $off->getAttribute('x') / 914400,
                'y' => (int) $off->getAttribute('y') / 914400,
                'w' => (int) $ext->getAttribute('cx') / 914400,
                'h' => (int) $ext->getAttribute('cy') / 914400,
                'text' => $text,
            ];
        }

        return $boxes;
    }

    /**
     * @return list<array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}>  線分([x1,y1],[x2,y2])、inch単位
     */
    private function lineSegments(string $slideXml): array
    {
        $dom = new \DOMDocument;
        $dom->loadXML($slideXml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

        $segments = [];
        foreach ($xp->query('//p:cxnSp') as $cxn) {
            $xfrm = $xp->query('./p:spPr/a:xfrm', $cxn)->item(0);
            $off = $xp->query('./a:off', $xfrm)->item(0);
            $ext = $xp->query('./a:ext', $xfrm)->item(0);
            $x = (int) $off->getAttribute('x') / 914400;
            $y = (int) $off->getAttribute('y') / 914400;
            $w = (int) $ext->getAttribute('cx') / 914400;
            $h = (int) $ext->getAttribute('cy') / 914400;
            $flipH = $xfrm->getAttribute('flipH') === '1';
            $flipV = $xfrm->getAttribute('flipV') === '1';
            // 既定は左上→右下。flipH/flipVの片方だけなら左下→右上。
            $segments[] = ($flipH xor $flipV)
                ? [[$x, $y + $h], [$x + $w, $y]]
                : [[$x, $y], [$x + $w, $y + $h]];
        }

        return $segments;
    }

    /** 線分が矩形(縮小済み)と交わるか(Liang-Barsky)。 */
    private function segmentIntersectsRect(array $segment, float $left, float $top, float $right, float $bottom): bool
    {
        [[$x1, $y1], [$x2, $y2]] = $segment;
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $t0 = 0.0;
        $t1 = 1.0;
        foreach ([[-$dx, $x1 - $left], [$dx, $right - $x1], [-$dy, $y1 - $top], [$dy, $bottom - $y1]] as [$p, $q]) {
            if ($p == 0) {
                if ($q < 0) {
                    return false;
                }

                continue;
            }
            $r = $q / $p;
            if ($p < 0) {
                if ($r > $t1) {
                    return false;
                }
                $t0 = max($t0, $r);
            } else {
                if ($r < $t0) {
                    return false;
                }
                $t1 = min($t1, $r);
            }
        }

        return true;
    }

    /**
     * 文字を持つテキストボックス同士、および線(ヘキサゴンの輪郭)とテキスト
     * ボックスが重ならないこと。
     */
    private function assertNoOverlaps(string $slideXml, string $label): void
    {
        $boxes = $this->textBoxes($slideXml);
        $eps = 0.012;

        foreach ($boxes as $i => $a) {
            foreach ($boxes as $j => $b) {
                if ($j <= $i) {
                    continue;
                }
                $overlapW = min($a['x'] + $a['w'], $b['x'] + $b['w']) - max($a['x'], $b['x']);
                $overlapH = min($a['y'] + $a['h'], $b['y'] + $b['h']) - max($a['y'], $b['y']);
                $this->assertFalse(
                    $overlapW > $eps && $overlapH > $eps,
                    "{$label}: 「{$a['text']}」と「{$b['text']}」の文字枠が重なっている",
                );
            }
        }

        foreach ($this->lineSegments($slideXml) as $segment) {
            foreach ($boxes as $box) {
                $this->assertFalse(
                    $this->segmentIntersectsRect($segment, $box['x'] + $eps, $box['y'] + $eps, $box['x'] + $box['w'] - $eps, $box['y'] + $box['h'] - $eps),
                    "{$label}: ヘキサゴンの線が「{$box['text']}」の文字枠に重なっている",
                );
            }
        }
    }

    /**
     * @param  list<string>  $names
     * @param  list<int>  $insufficient  材料不足にする競合の添字
     */
    private function wheelData(array $names, array $insufficient = [], bool $selfReadable = true, bool $selfSufficient = true): array
    {
        $companies = [['name' => $names[0], 'matched' => 16, 'total' => 24, 'is_self' => true, 'material_sufficient' => $selfSufficient]];
        $count = count($names) - 1;
        for ($i = 0; $i < $count; $i++) {
            $companies[] = ['name' => $names[$i + 1], 'matched' => 18 + $i, 'total' => 24, 'is_self' => false, 'material_sufficient' => ! in_array($i, $insufficient, true)];
        }
        $axes = array_map(fn (string $name) => [
            'name' => $name,
            'caption' => null,
            'denominator' => 4,
            'self_count' => 2,
            'competitor_counts' => array_fill(0, $count, 3),
            'self_gap' => true,
        ], ['活動的魅力', '資産的魅力', '経営スタイル', '就業環境', '情緒的便益', '金銭的便益']);

        return ['self_readable' => $selfReadable, 'companies' => $companies, 'axes' => $axes, 'source_note' => 'テスト用ノート', 'page_number' => null];
    }

    /**
     * 依頼CL-1: 競合1・3・4・5社のそれぞれで、図形と文字が重ならない。
     * 長い企業名・材料不足の会社が混ざる場合も同様。
     */
    public function test_wheel_comparison_has_no_overlaps_for_1_3_4_and_5_competitors_including_long_names_and_insufficient_material(): void
    {
        $long = '株式会社ものすごく長い名前のためのテスト用ダミー企業ホールディングスグループ（東京）';
        $cases = [
            '競合1社' => [['自社テスト株式会社', '競合A株式会社'], []],
            '競合3社' => [['自社テスト株式会社', '競合A株式会社', $long, '競合C'], []],
            '競合4社(材料不足2社)' => [['自社テスト株式会社', '競合A', '競合B', '競合C', '競合D'], [1, 3]],
            '競合5社(長い名前・材料不足1社)' => [['自社テスト株式会社', '競合A', $long, '競合C', '競合D', $long], [4]],
            '競合3社(全員材料不足)' => [['自社テスト株式会社', '競合A', '競合B', '競合C'], [0, 1, 2]],
        ];

        foreach ($cases as $label => [$names, $insufficient]) {
            $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generate($this->wheelData($names, $insufficient)));
            $this->assertNoOverlaps($xml, $label);
            $this->assertSame(0, preg_match('/\br:(id|embed|link)="/', $xml), "{$label}: 外部参照が無いこと");
        }
    }

    /**
     * 依頼CD-3/CH-1b: 自社の判定が成立しない・材料不足のとき、数字の代わりの
     * 文言が出ても文字あふれ(他の文字枠との重なり)を起こさない。
     */
    public function test_wheel_comparison_has_no_overlaps_when_the_self_result_is_replaced_by_a_notice(): void
    {
        foreach ([[false, true], [true, false]] as [$readable, $sufficient]) {
            $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generate(
                $this->wheelData(['自社テスト株式会社', '競合A', '競合B', '競合C'], [], $readable, $sufficient),
            ));
            $this->assertNoOverlaps($xml, "readable={$readable},sufficient={$sufficient}");

            $notice = $readable
                ? (string) config('brand_wheel.insufficient_material_notice')
                : (string) config('admin_comparison_pptx.self_data_unavailable_notice');
            $this->assertStringContainsString(htmlspecialchars($notice, ENT_QUOTES | ENT_XML1), $xml);
            $this->assertStringNotContainsString('16 / 24', $xml, '0や点数を判定結果であるかのように出さない');
        }
    }

    /**
     * 依頼CL-1: 自社(左の列)が競合(中央の列)より左に、領域別の表(右の列)が
     * 競合より右にあること、自社のヘキサゴンが従来(半径0.65in)より大きいこと。
     */
    public function test_wheel_comparison_is_laid_out_in_three_columns_with_a_larger_self_hexagon(): void
    {
        $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generate($this->wheelData(['自社テスト株式会社', '競合A株式会社', '競合B株式会社'])));
        $boxes = collect($this->textBoxes($xml));

        $self = $boxes->first(fn ($b) => str_contains($b['text'], '自社テスト株式会社'));
        $competitor = $boxes->first(fn ($b) => str_contains($b['text'], '競合A株式会社'));
        $table = $boxes->first(fn ($b) => $b['text'] === '領域');
        $this->assertNotNull($self);
        $this->assertNotNull($competitor);
        $this->assertNotNull($table);
        $this->assertLessThan($competitor['x'], $self['x'], '自社が競合より左');
        $this->assertLessThan($table['x'], $competitor['x'], '表が競合より右');

        // 自社ヘキサゴンの外周(参照六角形)の高さ=2×半径。従来の半径0.65in(直径1.3in)より大きい。
        $segments = $this->lineSegments($xml);
        $ys = [];
        foreach ($segments as [[$x1, $y1], [$x2, $y2]]) {
            $ys[] = $y1;
            $ys[] = $y2;
        }
        $this->assertGreaterThan(2.0, max($ys) - min($ys), '自社ヘキサゴンの直径が2in以上(従来1.3in)');
    }

    /**
     * 依頼CL-1: 表の末尾に「合計」の行(各社の○の数/24)があり、(競合4〜5社では)ヘッダーが
     * 記号(自社/A〜)、中央の列の各社に同じ記号が付いている(依頼CM-4)。「自社が競合の
     * 最高値に届いていない領域」の強調(凡例)は残っている。
     */
    public function test_wheel_comparison_has_a_total_row_and_symbols_shared_between_the_table_and_the_competitor_tiles(): void
    {
        $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generate($this->wheelData(['自社テスト株式会社', '競合A', '競合B', '競合C', '競合D'])));

        $this->assertStringContainsString('合計]]>', $xml);
        $this->assertStringContainsString('16/24', $xml, '自社の合計');
        $this->assertStringContainsString('18/24', $xml);
        $this->assertStringContainsString('20/24', $xml);
        foreach (['A', 'B', 'C', 'D'] as $symbol) {
            $this->assertGreaterThanOrEqual(2, substr_count($xml, "<![CDATA[{$symbol}]]>") + substr_count($xml, "<![CDATA[{$symbol}　]]>"), "記号{$symbol}が表のヘッダーと中央の列の両方に出ること");
        }
        $this->assertStringContainsString('自社が競合の最高値未達', $xml);
        $this->assertStringContainsString('競合内の最高値', $xml);
        // 自社が競合の最高値(3)に届かない領域(self_count=2)は強調色(GAP_BG)の塗りが付く。
        $this->assertStringContainsString('FBEEE3', $xml);
    }

    // ------------------------------------------------------------------
    // 依頼CL-2(2026-10-05): 「求職者が知りたい情報と、自社サイト」。
    // ------------------------------------------------------------------

    /** 指定色で塗った文字枠でない図形(長方形)の数。 */
    private function countFilledRects(string $slideXml, string $rgb): int
    {
        $dom = new \DOMDocument;
        $dom->loadXML($slideXml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

        return $xp->query('//p:sp[not(p:nvSpPr/p:cNvSpPr[@txBox="1"])][p:spPr/a:solidFill/a:srgbClr[@val="'.$rgb.'"]]')->length;
    }

    /**
     * @return list<array{rank: int, key: string, name: string, percentage: float, self_state: string, mapped_count: int, self_matched_count: int, competitor_count: ?int}>
     */
    private function surveyRows(): array
    {
        return [
            ['rank' => 1, 'key' => 'job_content', 'name' => '希望するポジションの仕事・業務内容', 'percentage' => 24.6, 'self_state' => 'unconfirmed', 'mapped_count' => 1, 'self_matched_count' => 0, 'competitor_count' => 2],
            ['rank' => 2, 'key' => 'career_path', 'name' => '実現できるキャリアパス', 'percentage' => 23.8, 'self_state' => 'confirmed', 'mapped_count' => 1, 'self_matched_count' => 1, 'competitor_count' => 1],
            ['rank' => 3, 'key' => 'employee_interview', 'name' => '社員インタビュー', 'percentage' => 19.4, 'self_state' => 'partial', 'mapped_count' => 3, 'self_matched_count' => 1, 'competitor_count' => 3],
            ['rank' => 4, 'key' => 'training', 'name' => '研修制度', 'percentage' => 7.4, 'self_state' => 'not_applicable', 'mapped_count' => 0, 'self_matched_count' => 0, 'competitor_count' => null],
        ];
    }

    private function surveyData(array $overrides = []): array
    {
        return array_merge($this->comparisonData(), [
            'survey_comparison' => ['rows' => $this->surveyRows(), 'competitor_total' => 3, 'excluded_competitor_count' => 0],
        ], $overrides);
    }

    public function test_survey_comparison_slide_shows_the_four_states_in_percentage_order_without_external_refs(): void
    {
        $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generateSurveyComparisonSlide($this->surveyData()));

        $this->assertSame(0, preg_match('/\br:(id|embed|link)="/', $xml), '外部参照が無いこと(横棒は長方形の図形で描く)');
        $this->assertSame(0, substr_count($xml, 'schemeClr'));
        $this->assertGreaterThan(0, substr_count($xml, 'Meiryo'));

        $this->assertStringContainsString('求職者が知りたい情報と、自社サイト', $xml);
        $labels = (array) config('admin_comparison_pptx.survey_comparison_state_labels');
        foreach (['confirmed', 'partial', 'unconfirmed', 'not_applicable'] as $state) {
            $this->assertStringContainsString($labels[$state], $xml, "状態「{$labels[$state]}」が出ること");
        }
        $this->assertStringContainsString('確認できず', $xml);
        // 「ありません」「載せていない」と断定しない(巡回は最大ページ数までのため)。
        $this->assertStringNotContainsString('載せていない', $xml);
        $this->assertStringNotContainsString('発信していない', $xml);
        foreach ($labels as $label) {
            $this->assertStringNotContainsString('ありません', $label, '状態の表示そのものに断定の言い方を使わない');
        }

        // 割合の高い順(行の出現順 = 順位順)。
        $positions = array_map(fn (array $row) => strpos($xml, $row['name']), $this->surveyRows());
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, '割合の降順に行が並ぶこと');

        $this->assertStringContainsString('24.6%', $xml);
        $this->assertStringContainsString('7.4%', $xml);
        $this->assertStringContainsString('2社が掲載（3社中）', $xml);
        $this->assertStringContainsString('3社が掲載（3社中）', $xml);
    }

    public function test_survey_comparison_slide_highlights_unconfirmed_rows_and_draws_bars_as_rectangles(): void
    {
        $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generateSurveyComparisonSlide($this->surveyData()));

        // 「確認できず」の行は強調色(GAP_BG)の帯。確認できていた行(2行目)は帯なし(帯は奇数行のBAND)。
        $this->assertGreaterThanOrEqual(1, substr_count($xml, 'FBEEE3'));
        // 横棒: 長方形(AutoShape、文字枠ではない図形)。4行 → 棒4本
        // (確認できず以外の3行はCOPPER色、確認できずの行は強調色)。
        $this->assertSame(3, $this->countFilledRects($xml, 'C8763C'), '確認できず以外の3行の棒がCOPPER色の長方形');
        $this->assertSame(1, $this->countFilledRects($xml, 'A85B1E'), '確認できずの行の棒が強調色の長方形');
    }

    public function test_survey_comparison_slide_footer_has_source_scope_counting_and_not_applicable_notes(): void
    {
        $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generateSurveyComparisonSlide($this->surveyData()));

        $this->assertStringContainsString('OTOGI', $xml, '出典');
        $this->assertStringContainsString('prtimes.jp', $xml);
        $this->assertStringContainsString(htmlspecialchars(sprintf((string) config('admin_comparison_pptx.survey_comparison_note_scope'), (int) config('brand_wheel.crawl_max_pages')), ENT_QUOTES | ENT_XML1), $xml, '巡回の範囲の断り');
        $this->assertStringContainsString(htmlspecialchars((string) config('admin_comparison_pptx.survey_comparison_note_counting'), ENT_QUOTES | ENT_XML1), $xml, '自社と競合の数え方の違い');
        $this->assertStringContainsString(htmlspecialchars((string) config('admin_comparison_pptx.survey_comparison_note_not_applicable'), ENT_QUOTES | ENT_XML1), $xml, '判定の対象外の意味');
        $this->assertStringNotContainsString(htmlspecialchars(sprintf((string) config('admin_comparison_pptx.survey_comparison_note_excluded'), 1), ENT_QUOTES | ENT_XML1), $xml, '除外した競合が無いときは出ない');

        $withExcluded = $this->surveyData();
        $withExcluded['survey_comparison']['excluded_competitor_count'] = 1;
        $xml2 = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generateSurveyComparisonSlide($withExcluded));
        $this->assertStringContainsString(htmlspecialchars(sprintf((string) config('admin_comparison_pptx.survey_comparison_note_excluded'), 1), ENT_QUOTES | ENT_XML1), $xml2);
    }

    /**
     * 14件を1枚に収め、注記と行が重ならない(実データの件数)。
     */
    public function test_survey_comparison_slide_fits_all_fourteen_options_without_overlaps(): void
    {
        $rows = [];
        foreach (app(\App\Services\Report\CandidateSurveyCatalog::class)->options() as $i => $option) {
            $rows[] = ['rank' => $i + 1, 'key' => $option['key'], 'name' => $option['name'], 'percentage' => $option['percentage'], 'self_state' => 'unconfirmed', 'mapped_count' => 1, 'self_matched_count' => 0, 'competitor_count' => 3];
        }
        $this->assertCount(14, $rows);

        $data = $this->surveyData();
        $data['survey_comparison'] = ['rows' => $rows, 'competitor_total' => 5, 'excluded_competitor_count' => 1];
        $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generateSurveyComparisonSlide($data));

        $this->assertNoOverlaps($xml, '求職者が知りたい情報(14行)');
        foreach ($this->textBoxes($xml) as $box) {
            $this->assertLessThanOrEqual(7.5, $box['y'] + $box['h'], "「{$box['text']}」がスライドの下端に収まること");
        }
    }

    /**
     * 依頼CD-3/CH-1b: 自社の判定が成立しない・材料不足のときは、表の代わりに
     * 既存の文言を出す(表の行は出さない)。
     */
    public function test_survey_comparison_slide_shows_the_existing_notices_instead_of_the_table(): void
    {
        $unreadable = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generateSurveyComparisonSlide($this->surveyData(['self_readable' => false])));
        $this->assertStringContainsString(htmlspecialchars((string) config('admin_comparison_pptx.self_data_unavailable_notice'), ENT_QUOTES | ENT_XML1), $unreadable);
        $this->assertStringNotContainsString('希望するポジションの仕事・業務内容', $unreadable);

        $thin = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generateSurveyComparisonSlide($this->surveyData(['self_material_sufficient' => false])));
        $this->assertStringContainsString(htmlspecialchars((string) config('brand_wheel.insufficient_material_notice'), ENT_QUOTES | ENT_XML1), $thin);
        $this->assertStringNotContainsString('希望するポジションの仕事・業務内容', $thin);
    }

    /**
     * 依頼CL-2b: 「足りないもの」の冒頭の文は、アンケートを項目の選定に使って
     * いない事実と合う。「関心が高いにもかかわらず」とは書かない。
     */
    public function test_missing_items_intro_no_longer_claims_the_survey_was_used_to_choose_the_items(): void
    {
        $intro = (string) config('admin_comparison_pptx.missing_items_intro');
        $this->assertStringNotContainsString('関心が高い', $intro);
        $this->assertStringContainsString('参考', $intro);
        $this->assertStringContainsString('項目の選定や並び順には使っていません', $intro);
        // 依頼CM-6: 見出しと同じ内容(競合が伝えていて…確認できなかった)を繰り返さない。
        $this->assertStringNotContainsString('確認できなかった', $intro);
        $this->assertStringNotContainsString('競合', $intro);
        $heading = (string) config('admin_comparison_pptx.missing_items_heading');
        $this->assertStringContainsString('自社サイトでは確認できなかった項目', $heading);
        $this->assertStringNotContainsString('自社が伝えていない', $heading, '断定せず「確認できなかった」で統一する');

        $xml = $this->slideXmlOf($this->missingItemsSlideBytes());
        $this->assertStringContainsString(htmlspecialchars($intro, ENT_QUOTES | ENT_XML1), $xml);
    }

    /**
     * @return string  slide1.xmlの中身
     */
    private function slideXmlOf(string $bytes): string
    {
        $tmp = $this->reservedTempPath('slide', 'pptx');
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive;
        $zip->open($tmp);
        $slideXml = $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();

        return $slideXml;
    }

    /**
     * 依頼CB-4の中心要件(依頼CL-4で5枚): 説明→比較(CB-1)→足りないもの(CB-2)→
     * 求職者が知りたい情報と自社サイト(CL-2)→階層図(CB-3)→参照元の順で並ぶこと、
     * かつスライド番号・rId・sldIdが1枚ごとに進んでいて衝突・使い回しが無いこと。
     */
    public function test_inserts_all_five_slides_in_order_immediately_before_the_reference_page(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '内容2', '参照元']);

        $mergedPath = $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
        $this->tempFiles[] = $mergedPath;

        $zip = new ZipArchive;
        $zip->open($mergedPath);
        $presentationXml = $zip->getFromName('ppt/presentation.xml');
        $relsXml = $zip->getFromName('ppt/_rels/presentation.xml.rels');

        preg_match_all('/<p:sldId\s+id="(\d+)"\s+r:id="(rId\d+)"\s*\/>/', $presentationXml, $sldIdMatches, PREG_SET_ORDER);
        $this->assertCount(8, $sldIdMatches, '3枚+差し込み5枚(説明+比較+足りないもの+求職者が知りたい情報+階層図)=8枚になっていること');

        preg_match_all('/<Relationship\s+Id="(rId\d+)"[^>]*Target="([^"]+)"/', $relsXml, $relMatches, PREG_SET_ORDER);
        $targetByRid = [];
        foreach ($relMatches as $m) {
            $targetByRid[$m[1]] = $m[2];
        }

        $orderedTargets = array_map(fn ($m) => $targetByRid[$m[2]] ?? null, $sldIdMatches);

        // 元の3枚(slide1〜3)のうち、slide3(参照元)の直前に「説明→比較→
        // 足りないもの→求職者が知りたい情報→階層図」の順で新規スライドが
        // 入っていること。新規スライドのファイル名はslide4〜8.xml。
        $this->assertSame('slides/slide1.xml', $orderedTargets[0]);
        $this->assertSame('slides/slide2.xml', $orderedTargets[1]);
        $this->assertSame('slides/slide4.xml', $orderedTargets[2], '説明ページが最初に差し込まれていること');
        $this->assertSame('slides/slide5.xml', $orderedTargets[3], '比較ページが説明ページの直後にあること');
        $this->assertSame('slides/slide6.xml', $orderedTargets[4], '足りないものページが比較ページの直後にあること');
        $this->assertSame('slides/slide7.xml', $orderedTargets[5], '求職者が知りたい情報ページが足りないものページの直後にあること');
        $this->assertSame('slides/slide8.xml', $orderedTargets[6], '階層図ページが求職者が知りたい情報ページの直後にあること');
        $this->assertSame('slides/slide3.xml', $orderedTargets[7], '参照元スライド自体はそのまま最後に残ること');

        // rId・sldIdが1枚ごとに進んでいる(使い回されていない)こと。
        $newSldIds = array_column(array_slice($sldIdMatches, 2, 5), 1);
        $newRids = array_column(array_slice($sldIdMatches, 2, 5), 2);
        $this->assertSame($newSldIds, array_unique($newSldIds), 'sldIdが5枚とも異なること(使い回していないこと)');
        $this->assertSame($newRids, array_unique($newRids), 'rIdが5枚とも異なること(使い回していないこと)');
        for ($i = 1; $i < count($newSldIds); $i++) {
            $this->assertGreaterThan((int) $newSldIds[$i - 1], (int) $newSldIds[$i], 'sldIdが1枚ごとに進んでいること');
        }

        // 各スライドの中身に、取り違えなく固有の文言が入っていること。
        $explanationXml = $zip->getFromName('ppt/slides/slide4.xml');
        $comparisonXml = $zip->getFromName('ppt/slides/slide5.xml');
        $missingItemsXml = $zip->getFromName('ppt/slides/slide6.xml');
        $surveyXml = $zip->getFromName('ppt/slides/slide7.xml');
        $hierarchyXml = $zip->getFromName('ppt/slides/slide8.xml');
        $this->assertStringContainsString('ブランド・ホイール', $explanationXml);
        $this->assertStringContainsString('領域別の発信量', $comparisonXml);
        $this->assertStringNotContainsString('領域別の発信量', $explanationXml);
        $this->assertStringContainsString('足りないもの', $missingItemsXml);
        $this->assertStringNotContainsString('領域別の発信量', $missingItemsXml);
        $this->assertStringContainsString('求職者が知りたい情報と、自社サイト', $surveyXml);
        $this->assertStringNotContainsString('足りないもの', $surveyXml);
        $this->assertStringContainsString('自社サイトの階層図', $hierarchyXml);
        $this->assertStringNotContainsString('足りないもの', $hierarchyXml);

        $zip->close();
    }

    public function test_existing_parts_are_byte_identical_after_insertion(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '内容2', '参照元']);
        $beforeHashes = $this->hashAllEntries($deckPath);

        $mergedPath = $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
        $this->tempFiles[] = $mergedPath;
        $afterHashes = $this->hashAllEntries($mergedPath);

        $editedParts = ['ppt/presentation.xml', 'ppt/_rels/presentation.xml.rels', '[Content_Types].xml'];

        foreach ($beforeHashes as $name => $hash) {
            if (in_array($name, $editedParts, true)) {
                continue;
            }
            $this->assertSame($hash, $afterHashes[$name] ?? null, "既存パーツ {$name} のバイト列が変わっていないこと");
        }

        // 依頼CB-4(依頼CL-4で5枚): 追加パーツは10件(slide×5、rels×5)だけで
        // あること、削除は0件であること(全エントリ数の差分で確認)。書き換える3
        // ファイル([Content_Types].xml/presentation.xml.rels/
        // presentation.xml)は既存パーツのままエントリ数を増やさない。
        $this->assertCount(count($beforeHashes) + 10, $afterHashes, '追加パーツが10件(スライド5件+rels5件)だけであること');
    }

    public function test_layout_reference_matches_the_neighboring_slide_not_hardcoded(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '内容2', '参照元']);
        $mergedPath = $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
        $this->tempFiles[] = $mergedPath;

        $zip = new ZipArchive;
        $zip->open($mergedPath);
        // 依頼BZ-2/CB-4/CL-4: 5枚とも同じレイアウト(隣接スライードから引く
        // 既存方針)であること。
        foreach (['slide4', 'slide5', 'slide6', 'slide7', 'slide8'] as $slideName) {
            $rels = $zip->getFromName("ppt/slides/_rels/{$slideName}.xml.rels");
            $this->assertStringContainsString('slideLayouts/slideLayout1.xml', $rels, "{$slideName}のレイアウト参照");
        }
        $zip->close();
    }

    public function test_the_generated_pptx_opens_and_content_types_matches_added_slide(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '参照元']);
        $mergedPath = $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
        $this->tempFiles[] = $mergedPath;

        $zip = new ZipArchive;
        $openResult = $zip->open($mergedPath, ZipArchive::CHECKCONS);
        $this->assertTrue($openResult === true, 'ZipArchiveで整合性エラー無く開けること');

        $contentTypes = $zip->getFromName('[Content_Types].xml');
        foreach (['slide3', 'slide4', 'slide5', 'slide6', 'slide7'] as $slideName) {
            $this->assertNotFalse($zip->getFromName("ppt/slides/{$slideName}.xml"), "新規スライドファイル({$slideName})が存在すること");
            $this->assertStringContainsString("/ppt/slides/{$slideName}.xml", $contentTypes);
        }

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
            $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
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
            $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
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

        $mergedPath = $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
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

        $mergedPath = $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
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
            $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
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
            $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
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
            $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
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
            $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
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
            $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
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

        $mergedPath = $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
        $this->tempFiles[] = $mergedPath;

        $this->assertFileExists($mergedPath);
    }

    public function test_sldsz_with_cy_before_cx_is_read_correctly(): void
    {
        $deckPath = $this->makeFixtureDeck(
            ['内容1', '参照元'],
            sldSzXmlOverride: '<p:sldSz cy="6858000" cx="12192000"/>',
        );

        $mergedPath = $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
        $this->tempFiles[] = $mergedPath;

        $this->assertFileExists($mergedPath);
    }

    public function test_a_deck_without_sldsz_is_rejected_as_unreadable_not_a_default(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '参照元'], sldSzXmlOverride: '');

        try {
            $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
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

        $mergedPath = $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
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

        // 新規スライド5枚(slide4〜8.xml)が3〜7番目(=slide3「参照元一覧」の
        // 直前)に入っていること。slide1の「出典」には一切反応しないこと。
        $this->assertSame('slides/slide4.xml', $orderedTargets[2]);
        $this->assertSame('slides/slide5.xml', $orderedTargets[3]);
        $this->assertSame('slides/slide6.xml', $orderedTargets[4]);
        $this->assertSame('slides/slide7.xml', $orderedTargets[5]);
        $this->assertSame('slides/slide8.xml', $orderedTargets[6]);
        $this->assertSame('slides/slide3.xml', $orderedTargets[7]);
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
            $this->inserter()->insert($deckPath, $this->fiveSlideBytesList());
            $this->fail('例外が投げられるはず(本文の「出典」に誤って反応してはいけない)');
        } catch (ComparisonSlideInsertionException $e) {
            $this->assertStringContainsString('参照元', $e->getMessage());
        }
    }

    /**
     * 重なり検査(assertNoOverlaps)自体が、実際に重なった図形を検出できること
     * (検査が常に通るだけの空の検査になっていないことの確認)。
     */
    public function test_the_overlap_check_detects_overlapping_text_boxes_and_lines_crossing_text(): void
    {
        $ns = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';
        $box = fn (int $x, int $y, int $w, int $h, string $text) => '<p:sp><p:nvSpPr><p:cNvPr id="1" name=""/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr><p:spPr><a:xfrm><a:off x="'.$x.'" y="'.$y.'"/><a:ext cx="'.$w.'" cy="'.$h.'"/></a:xfrm></p:spPr><p:txBody><a:p><a:r><a:t>'.$text.'</a:t></a:r></a:p></p:txBody></p:sp>';
        $line = fn (int $x, int $y, int $w, int $h) => '<p:cxnSp><p:nvCxnSpPr><p:cNvPr id="2" name=""/><p:cNvCxnSpPr/><p:nvPr/></p:nvCxnSpPr><p:spPr><a:xfrm><a:off x="'.$x.'" y="'.$y.'"/><a:ext cx="'.$w.'" cy="'.$h.'"/></a:xfrm></p:spPr></p:cxnSp>';
        $wrap = fn (string $body) => '<?xml version="1.0" encoding="UTF-8"?><p:sld '.$ns.'><p:cSld><p:spTree>'.$body.'</p:spTree></p:cSld></p:sld>';

        $in = 914400;
        $overlapping = $wrap($box($in, $in, 2 * $in, $in, 'あ').$box((int) (1.5 * $in), (int) (1.5 * $in), 2 * $in, $in, 'い'));
        $separate = $wrap($box($in, $in, $in, $in, 'あ').$box(3 * $in, 3 * $in, $in, $in, 'い'));
        $lineThroughText = $wrap($box($in, $in, 2 * $in, $in, 'あ').$line(0, 0, 4 * $in, 4 * $in));

        $this->assertNoOverlaps($separate, '重ならない配置');

        foreach (['文字枠どうし' => $overlapping, '線と文字枠' => $lineThroughText] as $label => $xml) {
            try {
                $this->assertNoOverlaps($xml, $label);
                $this->fail("{$label}の重なりを検出できなかった");
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $this->assertStringContainsString('重なっている', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------
    // 依頼CM-3/CM-4/CM-5(2026-10-06): 企業名の折り方・表の見出し・桁。
    // ------------------------------------------------------------------

    /**
     * 文字枠ごとの、改行(a:br・段落)で区切った行。
     *
     * @return list<array{lines: list<string>, text: string}>
     */
    private function textBoxLines(string $slideXml): array
    {
        $dom = new \DOMDocument;
        $dom->loadXML($slideXml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

        $result = [];
        foreach ($xp->query('//p:sp[p:nvSpPr/p:cNvSpPr[@txBox="1"]]') as $sp) {
            $lines = [];
            foreach ($xp->query('.//a:p', $sp) as $paragraph) {
                $current = '';
                foreach ($paragraph->childNodes as $child) {
                    if ($child->nodeName === 'a:br') {
                        $lines[] = $current;
                        $current = '';
                    } elseif ($child->nodeName === 'a:r') {
                        foreach ($xp->query('./a:t', $child) as $textNode) {
                            $current .= $textNode->textContent;
                        }
                    }
                }
                $lines[] = $current;
            }
            $lines = array_values(array_filter($lines, fn (string $l) => trim($l) !== ''));
            if ($lines !== []) {
                $result[] = ['lines' => $lines, 'text' => implode('', $lines)];
            }
        }

        return $result;
    }

    /**
     * 企業名を表示している文字枠(の行)をすべて返す。記号(A　など)は除く。
     *
     * @return list<list<string>>
     */
    private function nameBoxesOf(string $slideXml, string $name): array
    {
        $compact = str_replace([' ', '　'], '', $name);
        $found = [];
        foreach ($this->textBoxLines($slideXml) as $box) {
            $lines = $box['lines'];
            $lines[0] = preg_replace('/^[A-E]　/u', '', $lines[0]) ?? $lines[0];
            if (str_replace([' ', '　'], '', implode('', $lines)) === $compact) {
                $found[] = $lines;
            }
        }

        return $found;
    }

    /** どの行も、語(トークン)を2行にまたがって割っていないこと。 */
    private function assertNoWordIsSplit(array $lines, array $words, string $label): void
    {
        $name = implode('', $lines);
        foreach ($words as $word) {
            if (! str_contains(str_replace([' ', '　'], '', $name), $word)) {
                continue;
            }
            $inOneLine = false;
            foreach ($lines as $line) {
                if (str_contains($line, $word)) {
                    $inOneLine = true;
                }
            }
            $this->assertTrue($inOneLine, "{$label}: 「{$word}」が行をまたいで割れている(".implode(' / ', $lines).')');
        }
    }

    private const NAME_WORDS = ['サイボウズ', 'マネーフォワード', 'フリー', 'Fuji', 'Innovation', 'レジェンダ', 'コーポレーション', '株式会社'];

    /**
     * 依頼CM-3: 実物で「サイボウズ/株式会社」「株式会社マネ/ーフォワード」と語の途中で
     * 切れていた企業名が、語の切れ目で折れる(競合1〜3社=企業名の見出し、4〜5社=記号)。
     */
    public function test_company_names_are_never_split_in_the_middle_of_a_word(): void
    {
        $names = ['サイボウズ株式会社', '株式会社マネーフォワード', 'フリー株式会社', '株式会社Fuji of Innovation', 'レジェンダ・コーポレーション株式会社'];
        $cases = [
            '競合3社(企業名の見出し)' => ['自社テスト株式会社', ...array_slice($names, 0, 3)],
            '競合3社(後ろの2社)' => ['レジェンダ・コーポレーション株式会社', $names[3], $names[4], $names[0]],
            '競合4社(記号)' => ['自社テスト株式会社', ...array_slice($names, 0, 4)],
            '競合5社(記号)' => ['自社テスト株式会社', ...$names],
        ];

        foreach ($cases as $label => $companyNames) {
            $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generate($this->wheelData($companyNames)));
            foreach (array_slice($companyNames, 1) as $name) {
                $boxes = $this->nameBoxesOf($xml, $name);
                $this->assertNotEmpty($boxes, "{$label}: 「{$name}」が表示されている");
                foreach ($boxes as $lines) {
                    $this->assertStringNotContainsString('…', implode('', $lines), "{$label}: 「{$name}」は省略されない");
                    $this->assertNoWordIsSplit($lines, self::NAME_WORDS, $label);
                }
            }
            $this->assertNoOverlaps($xml, $label);
        }
    }

    public function test_company_names_break_before_or_after_the_corporate_form_and_at_spaces_and_middle_dots(): void
    {
        $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generate($this->wheelData(['自社', '株式会社マネーフォワード', 'レジェンダ・コーポレーション株式会社'])));

        // 「株式会社」の前後で折れる(「株式会社」/「マネーフォワード」)。
        $this->assertContains(['株式会社', 'マネーフォワード'], $this->nameBoxesOf($xml, '株式会社マネーフォワード'));
        // 「・」の後ろで折れる。
        $boxes = $this->nameBoxesOf($xml, 'レジェンダ・コーポレーション株式会社');
        $this->assertNotEmpty($boxes);
        foreach ($boxes as $lines) {
            $this->assertGreaterThan(1, count($lines));
            $this->assertTrue(
                str_ends_with($lines[0], '・') || str_ends_with($lines[0], '株式会社') || str_starts_with($lines[1] ?? '', '株式会社'),
                '語の切れ目(・の後ろ/株式会社の前後)で折れている: '.implode(' / ', $lines),
            );
        }

        // 空白で折れ、英数字どうしの境目には空白が戻る。
        $xml2 = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generate($this->wheelData(['自社', '株式会社Fuji of Innovation', '競合B', '競合C', '競合D'])));
        foreach ($this->nameBoxesOf($xml2, '株式会社Fuji of Innovation') as $lines) {
            $this->assertNoWordIsSplit($lines, ['Fuji', 'Innovation', '株式会社'], '空白区切りの企業名');
        }
    }

    /** 1行に収まるなら折らない。 */
    public function test_a_name_that_fits_on_one_line_is_not_broken(): void
    {
        $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generate($this->wheelData(['自社テスト株式会社', 'フリー株式会社', '競合B'])));

        foreach ($this->nameBoxesOf($xml, 'フリー株式会社') as $lines) {
            $this->assertSame(['フリー株式会社'], $lines);
        }
        foreach ($this->nameBoxesOf($xml, '自社テスト株式会社') as $lines) {
            $this->assertSame(['自社テスト株式会社'], $lines, '自社の名前(幅が広い)も折らない');
        }
    }

    /** 極端に長い名前だけ、既存の省略の処理に落ちる(図形は崩れない)。 */
    public function test_an_extremely_long_name_falls_back_to_the_existing_ellipsis(): void
    {
        $long = str_repeat('ものすごく長い企業名', 12).'株式会社';
        $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generate($this->wheelData(['自社テスト株式会社', $long, '競合B'])));

        $this->assertStringContainsString('…', $xml);
        $this->assertStringNotContainsString($long, $xml);
        $this->assertNoOverlaps($xml, '極端に長い名前');
    }

    /** 文字を小さくして1行に収まるときは、下限(7pt)より小さくしない。 */
    public function test_the_font_is_never_shrunk_below_the_configured_floor(): void
    {
        $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generate($this->wheelData(['自社テスト株式会社', '株式会社マネーフォワード', 'レジェンダ・コーポレーション株式会社', '株式会社Fuji of Innovation'])));

        preg_match_all('/<a:rPr[^>]*\bsz="(\d+)"/', $xml, $m);
        $floor = (int) config('admin_comparison_pptx.company_name_absolute_min_pt');
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $sz) {
            $this->assertGreaterThanOrEqual($floor * 100, (int) $sz, '文字の大きさが下限より小さくない');
        }
    }

    /** 折り方の語・文字は config から出る(直書きしない)。 */
    public function test_the_break_words_come_from_config(): void
    {
        config(['admin_comparison_pptx.company_name_break_words' => ['ホールディングス']]);
        $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generate($this->wheelData(['自社', 'テクノロジーズホールディングス', '競合B', '競合C', '競合D'])));

        $boxes = $this->nameBoxesOf($xml, 'テクノロジーズホールディングス');
        $this->assertNotEmpty($boxes);
        foreach ($boxes as $lines) {
            $this->assertSame(['テクノロジーズ', 'ホールディングス'], $lines, 'config の語の前後で折れる');
        }
    }

    // ---- CM-4: 表の見出し ----

    public function test_with_one_to_three_competitors_the_table_header_shows_company_names_and_no_symbols(): void
    {
        foreach ([1, 2, 3] as $count) {
            $names = ['自社テスト株式会社'];
            for ($i = 1; $i <= $count; $i++) {
                $names[] = "競合{$i}株式会社";
            }
            $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generate($this->wheelData($names)));
            $boxes = $this->textBoxLines($xml);

            // 見出し(表のヘッダー行)と中央の列の両方に、企業名が出る。
            for ($i = 1; $i <= $count; $i++) {
                $this->assertGreaterThanOrEqual(2, count($this->nameBoxesOf($xml, "競合{$i}株式会社")), "競合{$count}社: 「競合{$i}株式会社」が見出しと中央の列の両方に出る");
            }
            // 記号(A〜)は出さない。注記も出さない。
            foreach (['A', 'B', 'C'] as $symbol) {
                foreach ($boxes as $box) {
                    $this->assertDoesNotMatchRegularExpression('/^'.$symbol.'(　|$)/u', $box['text'], "競合{$count}社: 記号{$symbol}を使わない");
                }
            }
            $this->assertStringNotContainsString('中央の競合の記号', $xml);
            $this->assertStringContainsString('軸の並びは自社の図と共通です。', $xml, '軸の並びの注記は残る');
            $this->assertNoOverlaps($xml, "競合{$count}社(企業名の見出し)");
        }
    }

    public function test_with_four_or_five_competitors_the_table_header_uses_symbols_and_the_legend_explains_them(): void
    {
        foreach ([4, 5] as $count) {
            $names = ['自社テスト株式会社'];
            for ($i = 1; $i <= $count; $i++) {
                $names[] = "競合{$i}株式会社";
            }
            $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generate($this->wheelData($names)));

            $last = ['A', 'B', 'C', 'D', 'E'][$count - 1];
            $this->assertStringContainsString("表のA〜{$last}は、中央の競合の記号です。", $xml);
            foreach (array_slice(['A', 'B', 'C', 'D', 'E'], 0, $count) as $symbol) {
                $this->assertGreaterThanOrEqual(2, substr_count($xml, "<![CDATA[{$symbol}]]>") + substr_count($xml, "<![CDATA[{$symbol}　]]>"), "競合{$count}社: 記号{$symbol}が見出しと中央の列の両方に出る");
            }
            $this->assertNoOverlaps($xml, "競合{$count}社(記号)");
        }
    }

    // ---- CM-5: 割合の桁 ----

    public function test_the_survey_table_percentages_are_aligned_to_one_decimal_place(): void
    {
        $data = $this->surveyData();
        $data['survey_comparison']['rows'][2]['percentage'] = 17.0;
        $xml = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generateSurveyComparisonSlide($data));

        $this->assertStringContainsString('17.0%', $xml);
        $this->assertStringContainsString('24.6%', $xml);
        $this->assertStringContainsString('7.4%', $xml);
        $this->assertStringNotContainsString('>17%<', str_replace(['<![CDATA[', ']]>'], ['>', '<'], $xml));

        // 「足りないもの」の文中の表記(17を17、13.4を13.4)は変えない。
        $missing = $this->slideXmlOf(app(AdminComparisonPptxGenerator::class)->generateMissingItemsSlide($this->comparisonData()));
        $this->assertStringContainsString('13.4%', $missing);
        $template = (string) config('admin_comparison_pptx.missing_item_survey_template');
        $this->assertSame('「X」を確認したい求職者が17%いますが、自社サイトでは確認できませんでした。', sprintf($template, 'X', '17'));
    }
}
