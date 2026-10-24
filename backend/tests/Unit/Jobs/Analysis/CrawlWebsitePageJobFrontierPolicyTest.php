<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\PageType;
use App\Jobs\Analysis\CrawlWebsitePageJob;
use App\Jobs\Analysis\RenderCrawledPageJob;
use App\Models\Analysis;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\Analysis\CrawlLinkExtractor;
use App\Services\Analysis\CrawlOriginScopeResolver;
use App\Services\Analysis\CrawlPolicyResolver;
use App\Services\Analysis\HtmlSeoAnalyzer;
use App\Services\Analysis\PageHtmlResolver;
use App\Services\Analysis\RecruitmentTrackPageFilter;
use App\Services\Analysis\RobotsTxtParser;
use App\Services\Analysis\SafeHttpFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼DF(2026-10-22): 巡回で制度のページを先に回り(DF-1)、同じ親ディレクトリのページを
 * 上限で後回しにし(DF-2)、個人情報・規約のページを回らない(DF-3)。
 * ページ数・深さの上限と、起点の配下を先に回る順番(依頼CF-1)は変えない。
 */
class CrawlWebsitePageJobFrontierPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Analysis, 1: WebsiteAnalysis}
     */
    private function makeWebsiteAnalysisWithRecruitOrigin(string $recruitPath = '/recruit/'): array
    {
        $project = Project::factory()->create();
        $analysis = Analysis::factory()->for($project)->create(['crawl_site' => true]);
        $website = Website::factory()->for($project)->create(['is_primary' => true, 'url' => 'https://example.co.jp/']);
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        AnalysisPage::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'page_type' => PageType::Homepage,
            'url' => 'https://example.co.jp/',
            'final_url' => 'https://example.co.jp/',
            'http_status' => 200,
        ]);
        AnalysisPage::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'page_type' => PageType::Recruit,
            'url' => "https://example.co.jp{$recruitPath}",
            'final_url' => "https://example.co.jp{$recruitPath}",
            'http_status' => 200,
        ]);
        AnalysisPage::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'page_type' => PageType::Robots,
            'url' => 'https://example.co.jp/robots.txt',
            'http_status' => 404,
        ]);

        return [$analysis, $websiteAnalysis];
    }

    private function seedPending(WebsiteAnalysis $websiteAnalysis, string $url, int $depth = 1): AnalysisCrawledPage
    {
        $page = new AnalysisCrawledPage;
        $page->website_analysis_id = $websiteAnalysis->id;
        $page->url = $url;
        $page->depth = $depth;
        $page->discovered_via = 'link';
        $page->status = AnalysisCrawledPage::STATUS_PENDING;
        $page->save();

        return $page;
    }

    private function handle(Analysis $analysis, WebsiteAnalysis $websiteAnalysis): void
    {
        (new CrawlWebsitePageJob($analysis->id, $websiteAnalysis->id))->handle(
            app(AnalysisPipeline::class),
            app(SafeHttpFetcher::class),
            app(CrawlLinkExtractor::class),
            app(RobotsTxtParser::class),
            app(CrawlPolicyResolver::class),
            app(AnalysisStoragePaths::class),
            app(HtmlSeoAnalyzer::class),
            app(PageHtmlResolver::class),
            app(RecruitmentTrackPageFilter::class),
            app(CrawlOriginScopeResolver::class),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
        config(['analysis.ssrf_test_allowlist' => 'example.co.jp']);
        Http::fake([
            'https://example.co.jp/*' => Http::response('<html><body>ok</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
    }

    private function seedFetched(WebsiteAnalysis $websiteAnalysis, string $url, int $depth = 1): AnalysisCrawledPage
    {
        $page = $this->seedPending($websiteAnalysis, $url, $depth);
        $page->update(['status' => AnalysisCrawledPage::STATUS_FETCHED, 'final_url' => $url, 'http_status' => 200]);

        return $page;
    }

    public function test_a_priority_page_within_the_origin_is_fetched_before_other_pages_within_the_origin(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        $normal = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/staff/a.html', depth: 1);
        $priority = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/benefits/', depth: 2);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_FETCHED, $priority->fresh()->status);
        $this->assertSame(AnalysisCrawledPage::STATUS_PENDING, $normal->fresh()->status);
    }

    public function test_a_priority_page_outside_the_origin_does_not_come_before_pages_within_the_origin(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        $outside = $this->seedPending($websiteAnalysis, 'https://example.co.jp/company/benefits/', depth: 1);
        $within = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/staff/a.html', depth: 2);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_FETCHED, $within->fresh()->status);
        $this->assertSame(AnalysisCrawledPage::STATUS_PENDING, $outside->fresh()->status);
    }

    public function test_pages_under_a_parent_that_reached_the_soft_cap_are_deferred_but_still_fetched_when_nothing_else_is_left(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $this->seedFetched($websiteAnalysis, "https://example.co.jp/recruit/staff/{$name}.html");
        }
        $sixth = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/staff/f.html', depth: 1);
        $other = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/about/', depth: 2);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_FETCHED, $other->fresh()->status, '上限に達していない親のページが先');
        $this->assertSame(AnalysisCrawledPage::STATUS_PENDING, $sixth->fresh()->status, '6件目は捨てずに後回し');

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_FETCHED, $sixth->fresh()->status, '他に無ければ後回しのページも回る');
    }

    public function test_query_variants_count_as_the_same_parent(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        $this->seedFetched($websiteAnalysis, 'https://example.co.jp/recruit/career/');
        foreach ([2, 3, 4, 5] as $n) {
            $this->seedFetched($websiteAnalysis, "https://example.co.jp/recruit/career/?page={$n}");
        }
        $sixth = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/career/?page=6', depth: 1);
        $other = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/job/', depth: 2);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_FETCHED, $other->fresh()->status);
        $this->assertSame(AnalysisCrawledPage::STATUS_PENDING, $sixth->fresh()->status);
    }

    public function test_a_privacy_page_is_excluded_without_fetching_and_does_not_use_the_page_budget(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        $privacy = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/privacy-policy/', depth: 1);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_EXCLUDED_BY_PATTERN, $privacy->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'privacy-policy'));
        $this->assertSame(0, AnalysisCrawledPage::query()->where('website_analysis_id', $websiteAnalysis->id)->where('status', AnalysisCrawledPage::STATUS_FETCHED)->count());
    }

    public function test_links_are_enqueued_without_skipped_ones_and_with_priority_links_first(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();
        // setUp()の偽の応答(本文 ok)より先に当たるよう、偽の応答を作り直す。
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            'https://example.co.jp/*' => Http::response(
                '<html><body>'
                .'<a href="/recruit/staff/a.html">社員A</a>'
                .'<a href="/recruit/official-rules/sea.html">Recruitment Privacy Notice</a>'
                .'<a href="/recruit/page-12/">給与と評価制度</a>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/', depth: 0);

        $this->handle($analysis, $websiteAnalysis);

        $pendingUrls = AnalysisCrawledPage::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('status', AnalysisCrawledPage::STATUS_PENDING)
            ->orderBy('id')
            ->pluck('url')
            ->all();

        $this->assertSame([
            'https://example.co.jp/recruit/page-12/',
            'https://example.co.jp/recruit/staff/a.html',
        ], $pendingUrls, '個人情報の通知(リンクの文字で判定)は入らず、制度のリンクが先');
    }

    public function test_with_all_new_rules_and_origin_priority_disabled_the_previous_depth_id_order_is_kept(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        config([
            'brand_wheel.crawl_prioritize_origin_scope' => false,
            'brand_wheel.crawl_priority_enabled' => false,
            'brand_wheel.crawl_same_parent_soft_cap' => 0,
        ]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        $first = $this->seedPending($websiteAnalysis, 'https://example.co.jp/company/history.html', depth: 1);
        $priority = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/benefits/', depth: 2);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_FETCHED, $first->fresh()->status);
        $this->assertSame(AnalysisCrawledPage::STATUS_PENDING, $priority->fresh()->status);
    }

    public function test_the_completed_log_carries_only_counts_for_the_new_rules(): void
    {
        Queue::fake([CrawlWebsitePageJob::class, RenderCrawledPageJob::class]);
        config(['brand_wheel.crawl_max_pages' => 6]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $this->seedFetched($websiteAnalysis, "https://example.co.jp/recruit/staff/{$name}.html");
        }
        $this->seedFetched($websiteAnalysis, 'https://example.co.jp/recruit/benefits/');
        $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/staff/f.html');

        Log::spy();
        $this->handle($analysis, $websiteAnalysis);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            return $message === 'brand_wheel_crawl_completed'
                && $context['priority_fetched'] === 1
                && $context['same_parent_capped_parents'] === 1
                && $context['same_parent_deferred_pending'] === 1;
        })->once();
    }
}
