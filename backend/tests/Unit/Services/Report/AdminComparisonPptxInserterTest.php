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
            'candidate_survey_source_note' => (string) config('brand_wheel_candidate_survey.source_note'),
            'recommended_site_flow_names' => ['トップメッセージ'],
            'source_note' => 'テスト用ノート',
            'page_number' => null,
        ];
    }

    private function hierarchyData(): array
    {
        return [
            'origin_url' => 'https://example.com/recruit/',
            'branches' => [
                ['name' => 'careers', 'page_count' => 5, 'sample_pages' => ['インタビュー01', 'インタビュー02']],
            ],
            'other_branch_count' => 0,
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
     * 階層図(CB-3)の4枚になった。
     *
     * @return list<string>  insert()に渡す順の配列
     */
    private function fourSlideBytesList(): array
    {
        return [
            $this->explanationSlideBytes(),
            $this->comparisonSlideBytes(),
            $this->missingItemsSlideBytes(),
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
     * 依頼CB-2: 「足りないもの」スライドの外部参照ゼロ・内容(項目名・
     * 領域タグ・一文・候補者調査の対応・出典)を確認する。
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
        $this->assertStringContainsString('競合が伝えていて、自社が伝えていない項目', $slideXml);
        $this->assertStringContainsString('リーダーシップ', $slideXml);
        $this->assertStringContainsString('会社との距離', $slideXml, '領域タグが出ること');
        $this->assertStringContainsString('経営者・幹部の考え方や意思決定スタイルについての記述。', $slideXml, '一文(定義文ベース)が出ること');
        $this->assertStringContainsString('代表・経営層のインタビュー', $slideXml, '候補者調査の対応項目名が出ること');
        $this->assertStringContainsString('13.4', $slideXml, '候補者調査の割合が出ること');
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

        $this->assertStringContainsString('対応する項目なし', $slideXml2);
        $this->assertStringNotContainsString('％', $slideXml2);
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
     * 依頼CB-3: 「自社サイトの階層図」スライドの外部参照ゼロ・内容
     * (TOP/枝の名前・ページ数・代表ページ/推奨導線/巡回範囲の注記)を
     * 確認する。「ありません」と断定する文言がどこにも無いこと(必須)。
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
        $this->assertStringContainsString('careers', $slideXml);
        $this->assertStringContainsString('5ページ', $slideXml);
        $this->assertStringContainsString('インタビュー01', $slideXml);
        $this->assertStringContainsString('追加を検討したい導線', $slideXml);
        $this->assertStringContainsString('トップメッセージ', $slideXml);
        $this->assertStringContainsString('巡回は最大', $slideXml, '巡回範囲の注記が出ること');
        $this->assertStringContainsString('50', $slideXml, 'config(brand_wheel.crawl_max_pages)の値が埋め込まれること');

        // 依頼CB-3必須: 「ありません」と断定する文言が無いこと。
        $this->assertStringNotContainsString('ありません', $slideXml);
    }

    /**
     * 依頼CB-3必須: 巡回した範囲に1階層目の枝が1件も無いとき、ページが
     * 崩れず(空文字列や例外にならない)、「見つかりませんでした」の
     * 文言(「ありません」ではない)になること。
     */
    public function test_hierarchy_slide_handles_zero_branches_without_asserting_nonexistence(): void
    {
        $hierarchy = ['origin_url' => 'https://example.com/recruit/', 'branches' => [], 'other_branch_count' => 0];
        $bytes = app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide($this->comparisonData(), $hierarchy);
        $tmp = $this->reservedTempPath('slide', 'pptx');
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive;
        $zip->open($tmp);
        $slideXml = $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();

        $this->assertStringContainsString('見つかりませんでした', $slideXml);
        $this->assertStringNotContainsString('ありません', $slideXml);
    }

    /**
     * 依頼CB-4の中心要件: 説明→比較(CB-1)→足りないもの(CB-2)→階層図
     * (CB-3)→参照元の順で並ぶこと、かつスライド番号・rId・sldIdが1枚ごとに
     * 進んでいて衝突・使い回しが無いこと。
     */
    public function test_inserts_all_four_slides_in_order_immediately_before_the_reference_page(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '内容2', '参照元']);

        $mergedPath = $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
        $this->tempFiles[] = $mergedPath;

        $zip = new ZipArchive;
        $zip->open($mergedPath);
        $presentationXml = $zip->getFromName('ppt/presentation.xml');
        $relsXml = $zip->getFromName('ppt/_rels/presentation.xml.rels');

        preg_match_all('/<p:sldId\s+id="(\d+)"\s+r:id="(rId\d+)"\s*\/>/', $presentationXml, $sldIdMatches, PREG_SET_ORDER);
        $this->assertCount(7, $sldIdMatches, '3枚+差し込み4枚(説明+比較+足りないもの+階層図)=7枚になっていること');

        preg_match_all('/<Relationship\s+Id="(rId\d+)"[^>]*Target="([^"]+)"/', $relsXml, $relMatches, PREG_SET_ORDER);
        $targetByRid = [];
        foreach ($relMatches as $m) {
            $targetByRid[$m[1]] = $m[2];
        }

        $orderedTargets = array_map(fn ($m) => $targetByRid[$m[2]] ?? null, $sldIdMatches);

        // 元の3枚(slide1〜3)のうち、slide3(参照元)の直前に「説明→比較→
        // 足りないもの→階層図」の順で新規スライドが入っていること。
        // 新規スライドのファイル名はslide4〜7.xml(既存の最大+1〜+4)。
        $this->assertSame('slides/slide1.xml', $orderedTargets[0]);
        $this->assertSame('slides/slide2.xml', $orderedTargets[1]);
        $this->assertSame('slides/slide4.xml', $orderedTargets[2], '説明ページが最初に差し込まれていること');
        $this->assertSame('slides/slide5.xml', $orderedTargets[3], '比較ページが説明ページの直後にあること');
        $this->assertSame('slides/slide6.xml', $orderedTargets[4], '足りないものページが比較ページの直後にあること');
        $this->assertSame('slides/slide7.xml', $orderedTargets[5], '階層図ページが足りないものページの直後にあること');
        $this->assertSame('slides/slide3.xml', $orderedTargets[6], '参照元スライド自体はそのまま最後に残ること');

        // rId・sldIdが1枚ごとに進んでいる(使い回されていない)こと。
        $newSldIds = array_column(array_slice($sldIdMatches, 2, 4), 1);
        $newRids = array_column(array_slice($sldIdMatches, 2, 4), 2);
        $this->assertSame($newSldIds, array_unique($newSldIds), 'sldIdが4枚とも異なること(使い回していないこと)');
        $this->assertSame($newRids, array_unique($newRids), 'rIdが4枚とも異なること(使い回していないこと)');
        for ($i = 1; $i < count($newSldIds); $i++) {
            $this->assertGreaterThan((int) $newSldIds[$i - 1], (int) $newSldIds[$i], 'sldIdが1枚ごとに進んでいること');
        }

        // 各スライドの中身に、取り違えなく固有の文言が入っていること。
        $explanationXml = $zip->getFromName('ppt/slides/slide4.xml');
        $comparisonXml = $zip->getFromName('ppt/slides/slide5.xml');
        $missingItemsXml = $zip->getFromName('ppt/slides/slide6.xml');
        $hierarchyXml = $zip->getFromName('ppt/slides/slide7.xml');
        $this->assertStringContainsString('ブランド・ホイール', $explanationXml);
        $this->assertStringContainsString('領域別の発信量', $comparisonXml);
        $this->assertStringNotContainsString('領域別の発信量', $explanationXml);
        $this->assertStringContainsString('足りないもの', $missingItemsXml);
        $this->assertStringNotContainsString('領域別の発信量', $missingItemsXml);
        $this->assertStringContainsString('自社サイトの階層図', $hierarchyXml);
        $this->assertStringNotContainsString('足りないもの', $hierarchyXml);

        $zip->close();
    }

    public function test_existing_parts_are_byte_identical_after_insertion(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '内容2', '参照元']);
        $beforeHashes = $this->hashAllEntries($deckPath);

        $mergedPath = $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
        $this->tempFiles[] = $mergedPath;
        $afterHashes = $this->hashAllEntries($mergedPath);

        $editedParts = ['ppt/presentation.xml', 'ppt/_rels/presentation.xml.rels', '[Content_Types].xml'];

        foreach ($beforeHashes as $name => $hash) {
            if (in_array($name, $editedParts, true)) {
                continue;
            }
            $this->assertSame($hash, $afterHashes[$name] ?? null, "既存パーツ {$name} のバイト列が変わっていないこと");
        }

        // 依頼CB-4: 追加パーツは8件(slide×4、rels×4)だけであること、
        // 削除は0件であること(全エントリ数の差分で確認)。書き換える3
        // ファイル([Content_Types].xml/presentation.xml.rels/
        // presentation.xml)は既存パーツのままエントリ数を増やさない。
        $this->assertCount(count($beforeHashes) + 8, $afterHashes, '追加パーツが8件(スライド4件+rels4件)だけであること');
    }

    public function test_layout_reference_matches_the_neighboring_slide_not_hardcoded(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '内容2', '参照元']);
        $mergedPath = $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
        $this->tempFiles[] = $mergedPath;

        $zip = new ZipArchive;
        $zip->open($mergedPath);
        // 依頼BZ-2/CB-4: 4枚とも同じレイアウト(隣接スライードから引く
        // 既存方針)であること。
        foreach (['slide4', 'slide5', 'slide6', 'slide7'] as $slideName) {
            $rels = $zip->getFromName("ppt/slides/_rels/{$slideName}.xml.rels");
            $this->assertStringContainsString('slideLayouts/slideLayout1.xml', $rels, "{$slideName}のレイアウト参照");
        }
        $zip->close();
    }

    public function test_the_generated_pptx_opens_and_content_types_matches_added_slide(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '参照元']);
        $mergedPath = $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
        $this->tempFiles[] = $mergedPath;

        $zip = new ZipArchive;
        $openResult = $zip->open($mergedPath, ZipArchive::CHECKCONS);
        $this->assertTrue($openResult === true, 'ZipArchiveで整合性エラー無く開けること');

        $contentTypes = $zip->getFromName('[Content_Types].xml');
        foreach (['slide3', 'slide4', 'slide5', 'slide6'] as $slideName) {
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
            $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
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
            $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
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

        $mergedPath = $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
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

        $mergedPath = $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
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
            $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
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
            $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
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
            $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
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
            $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
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
            $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
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

        $mergedPath = $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
        $this->tempFiles[] = $mergedPath;

        $this->assertFileExists($mergedPath);
    }

    public function test_sldsz_with_cy_before_cx_is_read_correctly(): void
    {
        $deckPath = $this->makeFixtureDeck(
            ['内容1', '参照元'],
            sldSzXmlOverride: '<p:sldSz cy="6858000" cx="12192000"/>',
        );

        $mergedPath = $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
        $this->tempFiles[] = $mergedPath;

        $this->assertFileExists($mergedPath);
    }

    public function test_a_deck_without_sldsz_is_rejected_as_unreadable_not_a_default(): void
    {
        $deckPath = $this->makeFixtureDeck(['内容1', '参照元'], sldSzXmlOverride: '');

        try {
            $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
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

        $mergedPath = $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
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

        // 新規スライド4枚(slide4〜7.xml)が3〜6番目(=slide3「参照元一覧」の
        // 直前)に入っていること。slide1の「出典」には一切反応しないこと。
        $this->assertSame('slides/slide4.xml', $orderedTargets[2]);
        $this->assertSame('slides/slide5.xml', $orderedTargets[3]);
        $this->assertSame('slides/slide6.xml', $orderedTargets[4]);
        $this->assertSame('slides/slide7.xml', $orderedTargets[5]);
        $this->assertSame('slides/slide3.xml', $orderedTargets[6]);
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
            $this->inserter()->insert($deckPath, $this->fourSlideBytesList());
            $this->fail('例外が投げられるはず(本文の「出典」に誤って反応してはいけない)');
        } catch (ComparisonSlideInsertionException $e) {
            $this->assertStringContainsString('参照元', $e->getMessage());
        }
    }
}
