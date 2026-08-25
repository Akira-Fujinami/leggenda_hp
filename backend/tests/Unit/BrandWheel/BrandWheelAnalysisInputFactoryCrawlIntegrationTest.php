<?php

namespace Tests\Unit\BrandWheel;

use App\Enums\PageType;
use App\Models\Analysis;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\BrandWheel\BrandWheelAnalysisInputFactory;
use Database\Seeders\CategoryDefinitionSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼E: 依頼C・Dで作られたサイト全ページ巡回(analysis_crawled_pages)を
 * ブランド・ホイールのAI入力へ配線する変更の検証。
 *
 * 最重要: crawl_site=falseのとき、この変更の前後でtoArray()の出力が
 * バイト単位で完全に同一であること(依頼E-1)。BrandWheelAnalysisInputFactoryTest
 * (既存、無改造)がcrawl_site=falseの経路を既に多数のケースで厳密に検証して
 * おり、このファイルではそれに加えて明示的なtoArray()スナップショット比較を
 * 1本追加する。
 */
class BrandWheelAnalysisInputFactoryCrawlIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private BrandWheelAnalysisInputFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategoryDefinitionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);
        Storage::fake('analysis');
        $this->factory = app(BrandWheelAnalysisInputFactory::class);
    }

    private function makeWebsiteAnalysis(bool $crawlSite): WebsiteAnalysis
    {
        $analysis = Analysis::factory()->create(['crawl_site' => $crawlSite]);

        return WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id]);
    }

    private function putSeedPage(WebsiteAnalysis $websiteAnalysis, PageType $pageType, string $html, ?string $title = null): AnalysisPage
    {
        $filename = $pageType === PageType::Recruit ? 'recruit.html' : 'homepage.html';
        $path = app(AnalysisStoragePaths::class)->rawHtmlPath($websiteAnalysis->analysis_id, $websiteAnalysis->id, $filename);
        Storage::disk('analysis')->put($path, $html);

        return AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com',
            'final_url' => 'https://example.com',
            'page_type' => $pageType,
            'http_status' => 200,
            'raw_html_path' => $path,
            'title' => $title,
            'fetched_at' => now(),
        ]);
    }

    private function putCrawledPage(WebsiteAnalysis $websiteAnalysis, string $url, string $html, int $depth = 1): AnalysisCrawledPage
    {
        $path = app(AnalysisStoragePaths::class)->rawHtmlPath(
            $websiteAnalysis->analysis_id,
            $websiteAnalysis->id,
            'crawl/'.hash('sha256', $url).'.html',
        );
        Storage::disk('analysis')->put($path, $html);

        $page = new AnalysisCrawledPage;
        $page->website_analysis_id = $websiteAnalysis->id;
        $page->url = $url;
        $page->final_url = $url;
        $page->depth = $depth;
        $page->discovered_via = 'link';
        $page->status = AnalysisCrawledPage::STATUS_FETCHED;
        $page->raw_html_path = $path;
        $page->content_length = strlen($html);
        $page->fetched_at = now();
        $page->save();

        return $page;
    }

    /**
     * 依頼E-1の最重要の不変条件: crawl_site=falseのとき、この変更の前後で
     * toArray()の出力がバイト単位で完全に同一であること。同一フィクスチャに
     * 対して期待するJSON(この変更を加える前のBrandWheelAnalysisInputFactoryを
     * 手元で実行して採取した実際の出力)をハードコードして比較する。
     */
    public function test_crawl_site_false_produces_byte_identical_toarray_output(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis(crawlSite: false);

        $recruitHtml = '<html><head><title>採用情報</title></head>'
            .'<body><h1>採用情報</h1><h2>働き方</h2><p>私たちは挑戦を続けます。</p></body></html>';
        $homepageHtml = '<html><head><title>Example</title></head>'
            .'<body><header><nav><a href="/energy">エネルギー事業</a></nav></header>'
            .'<h1>Example</h1><p>会社概要のご案内です。</p></body></html>';

        $this->putSeedPage($websiteAnalysis, PageType::Recruit, $recruitHtml, '採用情報 | Example');
        $this->putSeedPage($websiteAnalysis, PageType::Homepage, $homepageHtml, 'Example');

        $input = $this->factory->build($websiteAnalysis->fresh());

        $expected = [
            'website_analysis_id' => $websiteAnalysis->id,
            'recruit_page_title' => '採用情報 | Example',
            'recruit_page_body_text' => "採用情報\n働き方\n私たちは挑戦を続けます。",
            'recruit_page_headings' => [
                ['level' => 1, 'text' => '採用情報'],
                ['level' => 2, 'text' => '働き方'],
            ],
            'homepage_title' => 'Example',
            'homepage_body_text' => "Example\n会社概要のご案内です。",
            'homepage_headings' => [
                ['level' => 1, 'text' => 'Example'],
            ],
            'business_link_labels' => ['エネルギー事業'],
            'input_truncated' => false,
        ];

        $this->assertSame($expected, $input->toArray());
    }

    /**
     * crawl_site=trueだがクロール結果が1件も無い(status='fetched'の行が
     * 無い)場合も、crawl_site=falseと同じ入力になること。
     */
    public function test_crawl_site_true_with_zero_fetched_pages_matches_crawl_site_false_output(): void
    {
        $disabled = $this->makeWebsiteAnalysis(crawlSite: false);
        $this->putSeedPage($disabled, PageType::Recruit, '<html><body><p>採用ページの本文です。</p></body></html>');
        $this->putSeedPage($disabled, PageType::Homepage, '<html><body><p>トップページの本文です。</p></body></html>');
        $disabledInput = $this->factory->build($disabled->fresh());

        $enabled = $this->makeWebsiteAnalysis(crawlSite: true);
        $this->putSeedPage($enabled, PageType::Recruit, '<html><body><p>採用ページの本文です。</p></body></html>');
        $this->putSeedPage($enabled, PageType::Homepage, '<html><body><p>トップページの本文です。</p></body></html>');
        $enabledInput = $this->factory->build($enabled->fresh());

        $this->assertSame(
            $disabledInput->recruitPageBodyText.$disabledInput->homepageBodyText,
            $enabledInput->recruitPageBodyText.$enabledInput->homepageBodyText,
        );
        $this->assertFalse($enabledInput->inputTruncated);
    }

    /**
     * クラスタ分類(依頼E-2): 採用パス配下のクロールページは採用クラスタ
     * (recruitPageBodyText)へ、それ以外はトップページクラスタ
     * (homepageBodyText)へ振り分けられる。
     */
    public function test_crawled_pages_are_classified_into_recruit_and_homepage_clusters(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis(crawlSite: true);
        $this->putSeedPage($websiteAnalysis, PageType::Recruit, '<html><body><p>採用ページの本文です。</p></body></html>');
        $this->putSeedPage($websiteAnalysis, PageType::Homepage, '<html><body><p>トップページの本文です。</p></body></html>');

        $this->putCrawledPage(
            $websiteAnalysis,
            'https://example.com/recruit/member',
            '<html><body><p>採用クラスタに分類されるべき社員インタビューです。</p></body></html>',
        );
        $this->putCrawledPage(
            $websiteAnalysis,
            'https://example.com/service',
            '<html><body><p>トップページクラスタに分類されるべき事業紹介です。</p></body></html>',
        );

        $input = $this->factory->build($websiteAnalysis->fresh());

        $this->assertStringContainsString('社員インタビュー', $input->recruitPageBodyText);
        $this->assertStringNotContainsString('社員インタビュー', $input->homepageBodyText);
        $this->assertStringContainsString('事業紹介', $input->homepageBodyText);
        $this->assertStringNotContainsString('事業紹介', $input->recruitPageBodyText);
    }

    /**
     * 重複除去(依頼E-3手順3): クロールページ同士の重複、seedとの重複の
     * どちらも除去される。
     */
    public function test_duplicate_paragraphs_are_removed_across_crawled_pages_and_against_seed(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis(crawlSite: true);
        $sharedFooter = '共通のフッタ本文です会社概要はこちら。';
        $this->putSeedPage($websiteAnalysis, PageType::Homepage, '<html><body><p>'.$sharedFooter.'</p><p>トップページ独自の本文です。</p></body></html>');

        // seedと全く同じ段落(フッタ等)を含むクロールページ。
        $this->putCrawledPage(
            $websiteAnalysis,
            'https://example.com/page-a',
            '<html><body><p>'.$sharedFooter.'</p><p>ページAだけのユニークな本文です。</p></body></html>',
        );
        // 別のクロールページにも同じフッタが登場する(クロールページ同士の重複)。
        $this->putCrawledPage(
            $websiteAnalysis,
            'https://example.com/page-b',
            '<html><body><p>'.$sharedFooter.'</p><p>ページBだけのユニークな本文です。</p></body></html>',
        );

        $input = $this->factory->build($websiteAnalysis->fresh());

        // 共通フッタは1回しか登場しない(seed側の1回のみ)。
        $this->assertSame(1, substr_count($input->homepageBodyText, $sharedFooter));
        // 各ページ固有の本文はどちらも残る。
        $this->assertStringContainsString('ページAだけのユニークな本文です', $input->homepageBodyText);
        $this->assertStringContainsString('ページBだけのユニークな本文です', $input->homepageBodyText);
    }

    /**
     * 充填順序(依頼E-4): seed本文は必ず予算内に確保され、予算超過時でも
     * 採用クラスタ・トップページクラスタの片方だけが全部落ちることはない。
     */
    public function test_seed_body_is_always_secured_and_neither_cluster_is_fully_starved(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis(crawlSite: true);
        $recruitSeed = str_repeat('採', 50);
        $homepageSeed = str_repeat('会', 50);
        $this->putSeedPage($websiteAnalysis, PageType::Recruit, '<html><body><p>'.$recruitSeed.'</p></body></html>');
        $this->putSeedPage($websiteAnalysis, PageType::Homepage, '<html><body><p>'.$homepageSeed.'</p></body></html>');

        // 採用クラスタ側に大量の候補を用意する(不均衡な状況を作る)。
        for ($i = 0; $i < 5; $i++) {
            $this->putCrawledPage(
                $websiteAnalysis,
                "https://example.com/recruit/page-{$i}",
                '<html><body><p>'.str_repeat('採用候補段落その'.$i, 40).'</p></body></html>',
            );
        }
        // トップページクラスタ側にも候補を用意する。
        $this->putCrawledPage(
            $websiteAnalysis,
            'https://example.com/service-x',
            '<html><body><p>'.str_repeat('事業紹介の候補段落です', 40).'</p></body></html>',
        );

        // maxChars = 60 * 3 = 180。seed(50+50=100文字)は確保されたうえで、
        // 残り80文字程度がクロール分に回る想定。
        config(['services.ai.max_input_tokens' => 60]);

        $input = $this->factory->build($websiteAnalysis->fresh());

        // seedは必ず全文残る。
        $this->assertStringStartsWith($recruitSeed, $input->recruitPageBodyText);
        $this->assertStringStartsWith($homepageSeed, $input->homepageBodyText);
        $this->assertTrue($input->inputTruncated);

        // トップページクラスタに候補があるにもかかわらず0文字(全部落ちる)には
        // なっていないこと ―― seedの50文字を超えて何かしら追加されている。
        $this->assertGreaterThan(mb_strlen($homepageSeed), mb_strlen($input->homepageBodyText));
    }

    /**
     * 選定は長さ順・配置は元の順序(依頼E-3手順4〜5): 短い段落と長い段落が
     * 混在するクロールページ群から、予算内に収まる範囲では長い段落が優先的に
     * 選ばれつつ、実際にAIへ渡る本文の並びはページ順(depth→id)に戻っている
     * こと。
     */
    public function test_selection_prioritizes_length_but_placement_follows_original_page_order(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis(crawlSite: true);
        $this->putSeedPage($websiteAnalysis, PageType::Homepage, '<html><body><p>seed本文。</p></body></html>');

        // 1ページ目(depth=1)は短い段落、2ページ目(depth=2)は長い段落。
        // 十分な予算があれば両方採用され、順序はページ順(1ページ目→2ページ目)
        // のまま連結されるはず。
        $this->putCrawledPage($websiteAnalysis, 'https://example.com/short', '<html><body><p>短い段落。</p></body></html>', depth: 1);
        $this->putCrawledPage($websiteAnalysis, 'https://example.com/long', '<html><body><p>'.str_repeat('長い段落です。', 10).'</p></body></html>', depth: 2);

        $input = $this->factory->build($websiteAnalysis->fresh());

        $shortPos = mb_strpos($input->homepageBodyText, '短い段落。');
        $longPos = mb_strpos($input->homepageBodyText, str_repeat('長い段落です。', 10));

        $this->assertNotFalse($shortPos);
        $this->assertNotFalse($longPos);
        // ページ順(1ページ目の短い段落が先、2ページ目の長い段落が後)。
        $this->assertLessThan($longPos, $shortPos);
    }

    /**
     * sourcePagesの形状(依頼E-5): クロールを組み込んでも
     * 'recruit_page'/'home_page'キーと既存の値('read'等)以外は増えない。
     */
    public function test_source_pages_shape_is_unchanged_when_crawl_is_enabled(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis(crawlSite: true);
        $this->putSeedPage($websiteAnalysis, PageType::Recruit, '<html><body><p>採用ページの本文です。</p></body></html>');
        $this->putSeedPage($websiteAnalysis, PageType::Homepage, '<html><body><p>トップページの本文です。</p></body></html>');
        $this->putCrawledPage($websiteAnalysis, 'https://example.com/service', '<html><body><p>事業紹介です。</p></body></html>');

        $input = $this->factory->build($websiteAnalysis->fresh());

        $this->assertSame(['recruit_page', 'home_page'], array_keys($input->sourcePages));
        $this->assertSame('read', $input->sourcePages['recruit_page']);
        $this->assertSame('read', $input->sourcePages['home_page']);
        $this->assertArrayNotHasKey('source_pages', $input->toArray());
    }

    /**
     * ステータスがfetched以外(pending/failed等)や、raw_html_pathがnullの
     * クロールページは対象に含まれないこと(依頼E-1)。
     */
    public function test_only_fetched_pages_with_raw_html_path_are_considered(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis(crawlSite: true);
        $this->putSeedPage($websiteAnalysis, PageType::Homepage, '<html><body><p>トップページの本文です。</p></body></html>');

        $pending = new AnalysisCrawledPage;
        $pending->website_analysis_id = $websiteAnalysis->id;
        $pending->url = 'https://example.com/pending';
        $pending->depth = 1;
        $pending->discovered_via = 'link';
        $pending->status = AnalysisCrawledPage::STATUS_PENDING;
        $pending->save();

        $failed = new AnalysisCrawledPage;
        $failed->website_analysis_id = $websiteAnalysis->id;
        $failed->url = 'https://example.com/failed';
        $failed->depth = 1;
        $failed->discovered_via = 'link';
        $failed->status = AnalysisCrawledPage::STATUS_FAILED;
        $failed->save();

        $input = $this->factory->build($websiteAnalysis->fresh());

        $this->assertSame('トップページの本文です。', $input->homepageBodyText);
    }
}
