<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\PageType;
use App\Jobs\Analysis\CrawlWebsitePageJob;
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
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼DS-2(2026-11-20): 外国語のページを回らない(2a)・配下の外のニュース/記事/製品を後回し(2b)・
 * 配下の外から取るページの数の上限(2c)。
 */
class CrawlWebsitePageJobFocusTest extends TestCase
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

    public function test_a_foreign_language_page_is_excluded_without_fetching(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        $english = $this->seedPending($websiteAnalysis, 'https://example.co.jp/e/recruit/', depth: 1);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_EXCLUDED_BY_PATTERN, $english->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/e/recruit/'));
    }

    public function test_a_language_directory_in_the_origin_is_crawled(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin('/en/careers/');

        $page = $this->seedPending($websiteAnalysis, 'https://example.co.jp/en/careers/jobs/', depth: 1);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_FETCHED, $page->fresh()->status);
    }

    public function test_foreign_language_links_are_not_enqueued(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();
        Http::swap(new Factory);
        Http::fake([
            'https://example.co.jp/*' => Http::response(
                '<html><body><a href="/en/">English</a><a href="/recruit/about/">About</a></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);
        $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/', depth: 0);

        $this->handle($analysis, $websiteAnalysis);

        $pending = AnalysisCrawledPage::query()->where('website_analysis_id', $websiteAnalysis->id)
            ->where('status', AnalysisCrawledPage::STATUS_PENDING)->pluck('url')->all();
        $this->assertSame(['https://example.co.jp/recruit/about/'], $pending);
    }

    public function test_product_pages_outside_the_origin_come_after_other_pages_outside_the_origin(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        $products = $this->seedPending($websiteAnalysis, 'https://example.co.jp/products/sensor/', depth: 1);
        $company = $this->seedPending($websiteAnalysis, 'https://example.co.jp/company/philosophy/', depth: 2);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_FETCHED, $company->fresh()->status);
        $this->assertSame(AnalysisCrawledPage::STATUS_PENDING, $products->fresh()->status, '捨てずに後回し');
    }

    public function test_column_pages_within_the_origin_are_not_deprioritized(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        $column = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/column/1.html', depth: 1);
        $other = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/about/', depth: 2);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_FETCHED, $column->fresh()->status, '配下の記事は従来どおり depth→id の順');
        $this->assertSame(AnalysisCrawledPage::STATUS_PENDING, $other->fresh()->status);
    }

    public function test_pages_outside_the_origin_stop_at_the_cap_and_the_crawl_ends_with_its_own_reason(): void
    {
        Queue::fake();
        config(['brand_wheel.crawl_outside_origin_max_pages' => 2]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        $this->seedFetched($websiteAnalysis, 'https://example.co.jp/recruit/');
        $this->seedFetched($websiteAnalysis, 'https://example.co.jp/company/a/');
        $this->seedFetched($websiteAnalysis, 'https://example.co.jp/company/b/');
        $within = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/about/', depth: 2);
        $outside = $this->seedPending($websiteAnalysis, 'https://example.co.jp/company/c/', depth: 1);

        $this->handle($analysis, $websiteAnalysis);
        $this->assertSame(AnalysisCrawledPage::STATUS_FETCHED, $within->fresh()->status, '配下は上限に関係なく回る');

        $this->handle($analysis, $websiteAnalysis);
        $this->assertSame(AnalysisCrawledPage::STATUS_PENDING, $outside->fresh()->status, '配下の外は上限に達したので回らない');
        $this->assertSame('outside_origin_cap', $websiteAnalysis->fresh()->crawl_finished_reason);
    }

    public function test_with_the_cap_off_pages_outside_the_origin_are_crawled_as_before(): void
    {
        Queue::fake();
        config(['brand_wheel.crawl_outside_origin_max_pages' => 0]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        $this->seedFetched($websiteAnalysis, 'https://example.co.jp/company/a/');
        $this->seedFetched($websiteAnalysis, 'https://example.co.jp/company/b/');
        $outside = $this->seedPending($websiteAnalysis, 'https://example.co.jp/company/c/', depth: 1);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_FETCHED, $outside->fresh()->status);
    }
}
