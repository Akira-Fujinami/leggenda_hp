<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Enums\PageType;
use App\Jobs\Analysis\CrawlWebsitePageJob;
use App\Jobs\Analysis\RenderCrawledPageJob;
use App\Models\Analysis;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\BrandWheelAnalysisResult;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\Analysis\CrawlLinkExtractor;
use App\Services\Analysis\CrawlPolicyResolver;
use App\Services\Analysis\HtmlSeoAnalyzer;
use App\Services\Analysis\PageHtmlResolver;
use App\Services\Analysis\RobotsTxtParser;
use App\Services\Analysis\SafeHttpFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼D-1: 「1ジョブ=1ページ」の連鎖本体。robots.txt遵守・ホスト許可
 * リスト・上限判定(ページ数/総経過時間/総容量)・1ページ失敗時の継続性は
 * 旧CrawlWebsiteJobTestから移設した。フロンティア(pending行)はテスト側で
 * 直接analysis_crawled_pagesに作る(CrawlWebsiteJobのseeding自体は
 * CrawlWebsiteJobTestで検証済みのため、ここではフロンティアが既にある
 * 状態から1ジョブぶんのhandle()を呼ぶ)。
 */
class CrawlWebsitePageJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsiteAnalysis(bool $robots404 = true): array
    {
        $project = Project::factory()->create();
        $analysis = Analysis::factory()->for($project)->create(['crawl_site' => true]);
        $website = Website::factory()->for($project)->create(['is_primary' => true, 'url' => 'https://example.co.jp']);
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        AnalysisPage::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'page_type' => PageType::Homepage,
            'url' => 'https://example.co.jp',
            'final_url' => 'https://example.co.jp',
            'http_status' => 200,
        ]);

        if ($robots404) {
            AnalysisPage::factory()->create([
                'website_analysis_id' => $websiteAnalysis->id,
                'page_type' => PageType::Robots,
                'url' => 'https://example.co.jp/robots.txt',
                'http_status' => 404,
            ]);
        }

        return [$analysis, $websiteAnalysis];
    }

    private function seedPending(WebsiteAnalysis $websiteAnalysis, string $url, int $depth = 1, string $status = AnalysisCrawledPage::STATUS_PENDING): AnalysisCrawledPage
    {
        $page = new AnalysisCrawledPage;
        $page->website_analysis_id = $websiteAnalysis->id;
        $page->url = $url;
        $page->depth = $depth;
        $page->discovered_via = 'link';
        $page->status = $status;
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
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
        config(['analysis.ssrf_test_allowlist' => 'example.co.jp,recruit.example.co.jp']);
        config(['brand_wheel.crawl_render_candidate_min_chars' => 200]);
        config(['brand_wheel.crawl_render_candidate_max_count' => 10]);
    }

    /**
     * 分割実行: フロンティアがDBに残り、次ジョブが継続すること。1件目の
     * handle()がpendingを1件処理して次をdispatchし、2件目のhandle()
     * (連鎖の次のジョブを模擬)が残りのpendingを処理する。
     */
    public function test_frontier_persists_in_the_database_and_the_next_job_continues_it(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $this->seedPending($websiteAnalysis, 'https://example.co.jp/page-1');
        $this->seedPending($websiteAnalysis, 'https://example.co.jp/page-2');

        Http::fake([
            'https://example.co.jp/page-1' => Http::response('<html><body>ok</body></html>', 200, ['Content-Type' => 'text/html']),
            'https://example.co.jp/page-2' => Http::response('<html><body>ok</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(1, AnalysisCrawledPage::query()->where('status', AnalysisCrawledPage::STATUS_FETCHED)->count());
        $this->assertSame(1, AnalysisCrawledPage::query()->where('status', AnalysisCrawledPage::STATUS_PENDING)->count());
        Queue::assertPushed(CrawlWebsitePageJob::class, 1);

        // 連鎖の次のジョブを模擬して2回目を実行する。
        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(2, AnalysisCrawledPage::query()->where('status', AnalysisCrawledPage::STATUS_FETCHED)->count());
        $this->assertSame(0, AnalysisCrawledPage::query()->where('status', AnalysisCrawledPage::STATUS_PENDING)->count());
    }

    /**
     * 依頼M-1: 巡回の進行に応じてCrawlWebsiteのAnalysisJob.progressが
     * 増加し、WebsiteAnalysis.progressにも反映されること。本番では
     * CrawlWebsiteJobが最初にmarkRunning(CrawlWebsite)を呼ぶため、ここでも
     * 同じ前提(Running状態のプレースホルダーが既に存在する)を再現する。
     */
    public function test_analysis_job_progress_increases_as_pages_are_processed(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        app(AnalysisPipeline::class)->markRunning($analysis->id, $websiteAnalysis->id, JobType::CrawlWebsite);

        $this->seedPending($websiteAnalysis, 'https://example.co.jp/page-1');
        $this->seedPending($websiteAnalysis, 'https://example.co.jp/page-2');
        $this->seedPending($websiteAnalysis, 'https://example.co.jp/page-3');
        $this->seedPending($websiteAnalysis, 'https://example.co.jp/page-4');

        Http::fake([
            'https://example.co.jp/*' => Http::response('<html><body>ok</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $progressAfterEachCall = [];
        for ($i = 0; $i < 4; $i++) {
            $this->handle($analysis, $websiteAnalysis);
            $job = \App\Models\AnalysisJob::query()
                ->where('website_analysis_id', $websiteAnalysis->id)
                ->where('job_type', JobType::CrawlWebsite)
                ->first();
            $progressAfterEachCall[] = $job->progress;
            $this->assertSame(AnalysisJobStatus::Running, $job->status);
        }

        // 単調増加(またはページ発見により一時的に足踏みすることはあっても
        // 減少はしない)であり、最初と最後で明確に動いていること。
        $this->assertLessThan($progressAfterEachCall[3], $progressAfterEachCall[0], 'progressが全く動いていない');
        for ($i = 1; $i < count($progressAfterEachCall); $i++) {
            $this->assertGreaterThanOrEqual($progressAfterEachCall[$i - 1], $progressAfterEachCall[$i]);
        }

        // WebsiteAnalysis.progressにも反映されている(0ではない)。
        $this->assertGreaterThan(0, $websiteAnalysis->fresh()->progress);
    }

    /**
     * 上限(ページ数)到達で正常終了する。fetched件数が上限に達した時点で
     * (pendingが残っていても)これ以上取得せず終端する。
     */
    public function test_terminates_normally_when_max_pages_is_reached(): void
    {
        Queue::fake([CrawlWebsitePageJob::class, RenderCrawledPageJob::class]);
        config(['brand_wheel.crawl_max_pages' => 0]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $this->seedPending($websiteAnalysis, 'https://example.co.jp/page-1');

        $this->handle($analysis, $websiteAnalysis);

        Http::assertNothingSent();
        Queue::assertNotPushed(CrawlWebsitePageJob::class);
        $this->assertSame(1, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }

    /**
     * 上限(総経過時間)到達で正常終了する。経過時間はDB側の開始時刻
     * (MIN(created_at))で判定する(依頼D-1、Job自体の$timeoutとは別軸)。
     */
    public function test_terminates_normally_when_total_timeout_is_reached(): void
    {
        Queue::fake([CrawlWebsitePageJob::class, RenderCrawledPageJob::class]);
        config(['brand_wheel.crawl_total_timeout_seconds' => 0]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $this->seedPending($websiteAnalysis, 'https://example.co.jp/page-1');

        $this->handle($analysis, $websiteAnalysis);

        Http::assertNothingSent();
        Queue::assertNotPushed(CrawlWebsitePageJob::class);
        $this->assertSame(1, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }

    /**
     * 上限(総容量)到達で正常終了する。合計はSUM(content_length)をジョブ
     * 開始時に毎回再計算する(独立プロセスの連鎖のためインメモリ変数を
     * 共有できない、依頼D-1)。
     */
    public function test_terminates_normally_when_max_storage_is_reached(): void
    {
        Queue::fake([CrawlWebsitePageJob::class, RenderCrawledPageJob::class]);
        config(['brand_wheel.crawl_max_total_storage_bytes' => 10]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $fetched = $this->seedPending($websiteAnalysis, 'https://example.co.jp/already-fetched', status: AnalysisCrawledPage::STATUS_FETCHED);
        $fetched->update(['content_length' => 100]);
        $this->seedPending($websiteAnalysis, 'https://example.co.jp/page-1');

        $this->handle($analysis, $websiteAnalysis);

        Http::assertNothingSent();
        Queue::assertNotPushed(CrawlWebsitePageJob::class);
    }

    /**
     * 1ページの取得失敗(500)で連鎖は止まらない ―― 失敗した行はhttp_status
     * つきで記録され、次のジョブが(遅延ありで)dispatchされる。
     */
    public function test_a_single_page_failure_does_not_halt_the_chain(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $this->seedPending($websiteAnalysis, 'https://example.co.jp/broken');

        Http::fake([
            'https://example.co.jp/broken' => Http::response('Internal Server Error', 500, ['Content-Type' => 'text/html']),
        ]);

        $this->handle($analysis, $websiteAnalysis);

        $row = AnalysisCrawledPage::query()->where('url', 'https://example.co.jp/broken')->first();
        $this->assertSame(AnalysisCrawledPage::STATUS_FAILED, $row->status);
        $this->assertSame(500, $row->http_status);

        Queue::assertPushed(CrawlWebsitePageJob::class, fn ($job) => $job->delay !== null);
    }

    /**
     * パス除外パターンに一致するpending行は取得せず除外扱いにする。HTTPを
     * 発行していないため、次のジョブは遅延なしでdispatchされる。
     */
    public function test_pattern_excluded_pages_are_skipped_without_a_delay(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        config(['brand_wheel.crawl_excluded_path_patterns' => ['/excluded/*']]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $this->seedPending($websiteAnalysis, 'https://example.co.jp/excluded/page');

        $this->handle($analysis, $websiteAnalysis);

        Http::assertNothingSent();
        $row = AnalysisCrawledPage::query()->where('url', 'https://example.co.jp/excluded/page')->first();
        $this->assertSame(AnalysisCrawledPage::STATUS_EXCLUDED_BY_PATTERN, $row->status);
        Queue::assertPushed(CrawlWebsitePageJob::class, fn ($job) => $job->delay === null);
    }

    /**
     * robots.txt(200)のDisallowで指定したパスは取得されない。
     */
    public function test_robots_disallowed_pages_are_skipped(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis(robots404: false);
        $robotsPath = app(AnalysisStoragePaths::class)->rawHtmlPath($analysis->id, $websiteAnalysis->id, 'robots.txt');
        Storage::disk('analysis')->put($robotsPath, "User-agent: *\nDisallow: /private\n");
        AnalysisPage::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'page_type' => PageType::Robots,
            'url' => 'https://example.co.jp/robots.txt',
            'http_status' => 200,
            'raw_html_path' => $robotsPath,
        ]);
        $this->seedPending($websiteAnalysis, 'https://example.co.jp/private');

        $this->handle($analysis, $websiteAnalysis);

        Http::assertNothingSent();
        $row = AnalysisCrawledPage::query()->where('url', 'https://example.co.jp/private')->first();
        $this->assertSame(AnalysisCrawledPage::STATUS_EXCLUDED_BY_ROBOTS, $row->status);
    }

    /**
     * 取得成功時、深さがmax_depth未満ならリンクを次の深さのpending行として
     * 追加する。max_depth以上なら追加しない。
     */
    public function test_extracts_and_enqueues_links_within_the_configured_max_depth(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        config(['brand_wheel.crawl_max_depth' => 2]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $this->seedPending($websiteAnalysis, 'https://example.co.jp/depth-1', depth: 1);

        Http::fake([
            'https://example.co.jp/depth-1' => Http::response('<html><body><a href="https://example.co.jp/depth-2">next</a></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->handle($analysis, $websiteAnalysis);

        $next = AnalysisCrawledPage::query()->where('url', 'https://example.co.jp/depth-2')->first();
        $this->assertNotNull($next);
        $this->assertSame(2, $next->depth);
        $this->assertSame(AnalysisCrawledPage::STATUS_PENDING, $next->status);
    }

    /**
     * レンダリング候補の選定: 静的本文がしきい値未満のfetched済みページのみ
     * 候補になり、RenderCrawledPageJobがdispatchされる。ブランド・ホイール
     * 分析はまだ起動されない(依頼D-4の順序保証)。
     */
    public function test_finalize_selects_pages_below_the_render_candidate_threshold(): void
    {
        Queue::fake([RenderCrawledPageJob::class]);
        config(['brand_wheel.crawl_render_candidate_min_chars' => 9999]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();

        $shortPagePath = app(AnalysisStoragePaths::class)->rawHtmlPath($analysis->id, $websiteAnalysis->id, 'crawl/short.html');
        Storage::disk('analysis')->put($shortPagePath, '<html><body>短い本文です。</body></html>');
        $short = $this->seedPending($websiteAnalysis, 'https://example.co.jp/short', status: AnalysisCrawledPage::STATUS_FETCHED);
        $short->update(['raw_html_path' => $shortPagePath]);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertTrue($short->fresh()->render_candidate);
        Queue::assertPushed(RenderCrawledPageJob::class, 1);
        $this->assertSame(0, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }

    /**
     * レンダリング候補の選定: しきい値以上の本文を持つページは候補に
     * ならず、候補0件の場合はRenderCrawledPageJobを起動せず、ブランド・
     * ホイール分析を直接起動する。
     */
    public function test_finalize_with_zero_candidates_dispatches_brand_wheel_directly(): void
    {
        Queue::fake([RenderCrawledPageJob::class]);
        config(['brand_wheel.crawl_render_candidate_min_chars' => 5]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();

        $longPagePath = app(AnalysisStoragePaths::class)->rawHtmlPath($analysis->id, $websiteAnalysis->id, 'crawl/long.html');
        Storage::disk('analysis')->put($longPagePath, '<html><body>'.str_repeat('十分に長い本文です。', 10).'</body></html>');
        $long = $this->seedPending($websiteAnalysis, 'https://example.co.jp/long', status: AnalysisCrawledPage::STATUS_FETCHED);
        $long->update(['raw_html_path' => $longPagePath]);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertFalse($long->fresh()->render_candidate);
        Queue::assertNotPushed(RenderCrawledPageJob::class);
        $this->assertSame(1, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }

    /**
     * レンダリング候補の選定: 候補の最大件数(N)を超えた分は選ばれない。
     * 優先度は深さの浅い順(依頼D-4)。
     */
    public function test_finalize_respects_the_max_candidate_count_preferring_shallower_depth(): void
    {
        Queue::fake([RenderCrawledPageJob::class]);
        config(['brand_wheel.crawl_render_candidate_min_chars' => 9999]);
        config(['brand_wheel.crawl_render_candidate_max_count' => 2]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();

        $pages = [];
        foreach ([3, 1, 2] as $i => $depth) {
            $path = app(AnalysisStoragePaths::class)->rawHtmlPath($analysis->id, $websiteAnalysis->id, "crawl/p{$i}.html");
            Storage::disk('analysis')->put($path, '<html><body>短文</body></html>');
            $page = $this->seedPending($websiteAnalysis, "https://example.co.jp/p{$i}", depth: $depth, status: AnalysisCrawledPage::STATUS_FETCHED);
            $page->update(['raw_html_path' => $path]);
            $pages[$depth] = $page;
        }

        $this->handle($analysis, $websiteAnalysis);

        $this->assertTrue($pages[1]->fresh()->render_candidate);
        $this->assertTrue($pages[2]->fresh()->render_candidate);
        $this->assertFalse($pages[3]->fresh()->render_candidate);
    }

    /**
     * failed()経路からも終端処理(finalizeCrawl)が呼ばれる(依頼D-2)。
     */
    public function test_failed_handler_finalizes_the_crawl(): void
    {
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();

        (new CrawlWebsitePageJob($analysis->id, $websiteAnalysis->id))->failed(new \RuntimeException('boom'));

        $this->assertSame(1, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }
}
