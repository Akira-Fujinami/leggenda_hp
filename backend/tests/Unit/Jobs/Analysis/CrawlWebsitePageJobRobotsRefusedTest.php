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
use App\Services\Analysis\RobotsAccessClassifier;
use App\Services\Analysis\RobotsTxtParser;
use App\Services\Analysis\SafeHttpFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼DT(2026-11-24): robots.txt を 401・403 で拒否されたサイトも巡回するが、ページ自体が拒否してきたら止める。
 * どのサイトでも 429 で止める。
 */
class CrawlWebsitePageJobRobotsRefusedTest extends TestCase
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

    private function robotsStatus(WebsiteAnalysis $websiteAnalysis, int $status): void
    {
        AnalysisPage::query()->where('website_analysis_id', $websiteAnalysis->id)->where('page_type', PageType::Robots)->update(['http_status' => $status]);
    }

    private function fakeResponse(int $status): void
    {
        Http::swap(new Factory);
        Http::fake(['https://example.co.jp/*' => Http::response('<html><body>no</body></html>', $status, ['Content-Type' => 'text/html'])]);
    }

    public function test_a_site_that_refused_the_robots_txt_is_crawled(): void
    {
        Queue::fake();
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();
        $this->robotsStatus($websiteAnalysis, 403);
        $page = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/about/');

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_FETCHED, $page->fresh()->status);
    }

    public function test_when_the_robots_txt_was_refused_a_refused_page_stops_the_crawl(): void
    {
        Queue::fake();
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();
        $this->robotsStatus($websiteAnalysis, 403);
        $this->fakeResponse(403);
        $first = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/about/');
        $second = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/job/', depth: 2);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_FAILED, $first->fresh()->status);
        $this->assertSame(AnalysisCrawledPage::STATUS_PENDING, $second->fresh()->status, '止めたので次のページは取りに行かない');
        $this->assertSame('blocked_by_server', $websiteAnalysis->fresh()->crawl_finished_reason);
        Queue::assertNotPushed(CrawlWebsitePageJob::class);

        $classified = app(RobotsAccessClassifier::class)->classify($websiteAnalysis->fresh());
        $this->assertSame(['class' => 'blocked', 'http_status' => 403], $classified, '1ページも取れずに止まったら「拒否していた」');
    }

    public function test_a_single_403_page_on_an_ordinary_site_does_not_stop_the_crawl(): void
    {
        Queue::fake();
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();
        $this->fakeResponse(403);
        $page = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/members/');

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_FAILED, $page->fresh()->status);
        $this->assertNull($websiteAnalysis->fresh()->crawl_finished_reason);
        Queue::assertPushed(CrawlWebsitePageJob::class);
    }

    public function test_a_429_page_stops_the_crawl_on_any_site(): void
    {
        Queue::fake();
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();
        $this->seedFetched($websiteAnalysis, 'https://example.co.jp/recruit/');
        $this->fakeResponse(429);
        $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/about/');

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame('blocked_by_server', $websiteAnalysis->fresh()->crawl_finished_reason);
        $this->assertNull(app(RobotsAccessClassifier::class)->classify($websiteAnalysis->fresh())['class'], '取れたページがあれば「拒否していた」とはしない');
    }
}
