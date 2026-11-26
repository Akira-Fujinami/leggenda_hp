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
 * 依頼CH追補-1(2026-10-01): BrandWheelAnalysisInput::$originChars(永続化され
 * BrandWheelMaterialSufficiencyが参照する値)の定義修正を検証する。
 *
 * 当初(依頼CH-1a)はクロール由来の起点URL配下文字数のみだったが、信金中央
 * 金庫の実例(起点URL配下のクロール文字数0、起点ページ本文自体は別に存在)
 * で「材料不足」を検出できないことが判明した。修正後は「起点ページ本文の
 * 段落合計＋起点URL配下のクロール段落合計」(段落間の改行は含まない)。
 */
class BrandWheelAnalysisInputFactoryOriginCharsTest extends TestCase
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

    private function makeWebsiteAnalysis(bool $crawlSite = true): WebsiteAnalysis
    {
        $analysis = Analysis::factory()->create(['crawl_site' => $crawlSite]);

        return WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id]);
    }

    private function putSeedPage(WebsiteAnalysis $wa, PageType $pageType, string $url, string $html): AnalysisPage
    {
        $filename = $pageType === PageType::Recruit ? 'recruit.html' : 'homepage.html';
        $path = app(AnalysisStoragePaths::class)->rawHtmlPath($wa->analysis_id, $wa->id, $filename);
        Storage::disk('analysis')->put($path, $html);

        return AnalysisPage::query()->create([
            'website_analysis_id' => $wa->id,
            'url' => $url,
            'final_url' => $url,
            'page_type' => $pageType,
            'http_status' => 200,
            'raw_html_path' => $path,
            'fetched_at' => now(),
        ]);
    }

    private function putCrawledPage(WebsiteAnalysis $wa, string $url, string $html, int $depth = 1): AnalysisCrawledPage
    {
        $path = app(AnalysisStoragePaths::class)->rawHtmlPath($wa->analysis_id, $wa->id, 'crawl/'.hash('sha256', $url).'.html');
        Storage::disk('analysis')->put($path, $html);

        $page = new AnalysisCrawledPage;
        $page->website_analysis_id = $wa->id;
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
     * 依頼CH追補-1必須の盲点対応: 起点ページ本文が十分にあり、巡回が
     * 完全に空振り(クロール結果が0件)でも、originCharsはnullにならず
     * 起点ページ本文の分を数えること。
     */
    public function test_origin_chars_counts_seed_body_even_when_crawl_finds_nothing(): void
    {
        $wa = $this->makeWebsiteAnalysis(crawlSite: true);
        $richBody = str_repeat('採', 4000);
        $this->putSeedPage($wa, PageType::Homepage, 'https://example.com/recruit/', '<html><body><p>'.$richBody.'</p></body></html>');
        // クロール結果は0件(status=fetchedの行を1件も作らない)。

        $input = $this->factory->build($wa->fresh());

        $this->assertNotNull($input->originChars);
        $this->assertSame(4000, $input->originChars);
    }

    /**
     * 依頼CH追補-1必須: 信金中央金庫を模したケース。起点ページ本文は
     * それなりにあるが短く、巡回で見つかった段落がすべて起点URL配下の
     * 「外」(別セクション)だった場合、originCharsは起点ページ本文の分だけに
     * とどまり、外のクロール文字数は加算されないこと。
     */
    public function test_origin_chars_excludes_crawled_paragraphs_outside_the_origin_scope(): void
    {
        $wa = $this->makeWebsiteAnalysis(crawlSite: true);
        $thinBody = str_repeat('採', 300);
        $this->putSeedPage($wa, PageType::Recruit, 'https://example.com/recruit/', '<html><body><p>'.$thinBody.'</p></body></html>');
        // 起点(/recruit/)の外にあるページ。
        $this->putCrawledPage($wa, 'https://example.com/ir/report', '<html><body><p>'.str_repeat('外部の段落', 200).'</p></body></html>');

        $input = $this->factory->build($wa->fresh());

        $this->assertSame(300, $input->originChars);
    }

    /**
     * 依頼CH追補-1必須: origin_chars配下のクロール段落は加算対象になること
     * (起点ページ本文＋起点URL配下のクロール段落の合計)。
     */
    public function test_origin_chars_adds_in_scope_crawled_paragraphs_to_the_seed_body(): void
    {
        $wa = $this->makeWebsiteAnalysis(crawlSite: true);
        $seedBody = str_repeat('採', 300);
        $this->putSeedPage($wa, PageType::Recruit, 'https://example.com/recruit/', '<html><body><p>'.$seedBody.'</p></body></html>');
        $inScopeBody = str_repeat('配下の段落', 100); // 500字
        $this->putCrawledPage($wa, 'https://example.com/recruit/member', '<html><body><p>'.$inScopeBody.'</p></body></html>');

        $input = $this->factory->build($wa->fresh());

        $this->assertSame(300 + mb_strlen($inScopeBody), $input->originChars);
    }

    /**
     * 依頼CH追補-1必須(「文字数で判定するなら、段落間の改行を含めない
     * こと」): 起点ページ本文が複数段落に分かれていても、段落を連結する
     * 改行はoriginCharsに含めないこと。
     */
    public function test_origin_chars_does_not_include_separators_between_seed_paragraphs(): void
    {
        $wa = $this->makeWebsiteAnalysis(crawlSite: true);
        // 3段落、各100字(合計300字)。本文全体の文字数(改行込み)は302字。
        $html = '<html><body>'
            .'<p>'.str_repeat('A', 100).'</p>'
            .'<p>'.str_repeat('B', 100).'</p>'
            .'<p>'.str_repeat('C', 100).'</p>'
            .'</body></html>';
        $this->putSeedPage($wa, PageType::Homepage, 'https://example.com/', $html);

        $input = $this->factory->build($wa->fresh());

        $this->assertSame(300, $input->originChars);
    }

    /**
     * crawl_site=falseでも起点ページ本文は数えること(CH追補の盲点対応は
     * クロール無効時にも及ぶ ―― 起点ページ本文そのものは巡回の有無と
     * 無関係に「起点由来の材料」であるため)。
     */
    public function test_origin_chars_is_counted_even_when_crawl_site_is_disabled(): void
    {
        $wa = $this->makeWebsiteAnalysis(crawlSite: false);
        $this->putSeedPage($wa, PageType::Homepage, 'https://example.com/', '<html><body><p>'.str_repeat('会', 250).'</p></body></html>');

        $input = $this->factory->build($wa->fresh());

        $this->assertSame(250, $input->originChars);
    }

    /**
     * 依頼DV: 枠が足りないとき、採用ページ(起点)の配下の段落を先に入れる(配下の外の記事などで枠が埋まらない)。
     */
    public function test_in_scope_paragraphs_are_taken_first_when_the_budget_is_short(): void
    {
        config(['services.ai.max_input_tokens' => 300]); // 900字。起点ページ本文300字を引いて、巡回の材料は600字。
        $build = function (float $share): int {
            config(['brand_wheel.crawl_origin_input_priority_share' => $share]);
            $wa = $this->makeWebsiteAnalysis(crawlSite: true);
            $this->putSeedPage($wa, PageType::Recruit, 'https://example.com/recruit/', '<html><body><p>'.str_repeat('採', 300).'</p></body></html>');
            $this->putCrawledPage($wa, 'https://example.com/baseball/news/1.html', '<html><body><p>'.str_repeat('記', 1000).'</p></body></html>');
            $this->putCrawledPage($wa, 'https://example.com/recruit/member/', '<html><body><p>'.str_repeat('配', 400).'</p></body></html>', depth: 2);

            return $this->factory->build($wa->fresh())->originChars;
        };

        $this->assertSame(300 + 400, $build(0.7), '配下の400字はすべて入る');
        $this->assertLessThan(300 + 400, $build(0.0), '0 にすると変更前(半分ずつの枠)で、配下の段落が切られる');
    }

    /**
     * 依頼DW: 配下の段落を取り切ったら、配下の外の段落は材料全体の30%まで(ニュース一覧で材料を埋めない)。
     */
    public function test_outside_paragraphs_are_limited_to_the_share_of_the_whole_material(): void
    {
        config(['services.ai.max_input_tokens' => 3000]); // 9,000字。枠には十分に余りがある。
        $wa = $this->makeWebsiteAnalysis(crawlSite: true);
        $this->putSeedPage($wa, PageType::Recruit, 'https://example.com/recruit/', '<html><body><p>'.str_repeat('採', 300).'</p></body></html>');
        $this->putCrawledPage($wa, 'https://example.com/recruit/member/', '<html><body><p>'.str_repeat('配', 400).'</p></body></html>');
        foreach (['baseball' => '野', 'soccer' => '蹴', 'gossip' => '芸'] as $section => $char) {
            $this->putCrawledPage($wa, "https://example.com/{$section}/", '<html><body><p>'.str_repeat($char, 1000).'</p></body></html>');
        }

        $input = $this->factory->build($wa->fresh());
        $total = mb_strlen(str_replace("\n", '', $input->recruitPageBodyText.$input->homepageBodyText));

        $this->assertSame(700, $input->originChars, '配下(起点ページ300字+配下400字)はすべて入る');
        $this->assertSame(700 + 300, $total, '外は 700×0.3/0.7=300字まで');

        config(['brand_wheel.crawl_outside_input_max_share' => 1.0]);
        $input = $this->factory->build($wa->fresh());
        $this->assertSame(700 + 3000, mb_strlen(str_replace("\n", '', $input->recruitPageBodyText.$input->homepageBodyText)), '1 にすると変更前(外も全部入る)');
    }
}
