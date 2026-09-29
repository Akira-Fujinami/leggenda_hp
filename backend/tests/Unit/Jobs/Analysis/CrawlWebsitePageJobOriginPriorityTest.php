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
 * 依頼CF-1(2026-09-29): 巡回の取得順序を「起点URL配下から」にする。
 * 範囲(許可ホスト・crawl_domain_scope・crawl_max_pages)は変えず、
 * pendingページを選ぶ「順序」だけを変える。実物の資料で観測された
 * 「巡回した50件のうち、起点URL配下にあったのは5件だけだった」を受けて、
 * 起点URL(採用ページ)配下のpendingページを、配下の外のpendingページより
 * 先に取得するようにする。
 */
class CrawlWebsitePageJobOriginPriorityTest extends TestCase
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

    /**
     * 実物の症状の再現: 起点(/recruit/)の外に発見順(depth/id)で先に並んで
     * いたページがあっても、起点配下のページを先に取得すること。
     */
    public function test_pending_pages_within_the_origin_scope_are_fetched_before_pages_outside_it(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        // 発見順(depth/id)では、起点の外(newsセクション)のほうが先に
        // 見つかっている ―― 何もしなければこちらが先に取得される状況。
        $outside = $this->seedPending($websiteAnalysis, 'https://example.co.jp/corporate/history.html', depth: 1);
        $within = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/careers.html', depth: 2);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_FETCHED, $within->fresh()->status, '起点配下のページが先に取得されること');
        $this->assertSame(AnalysisCrawledPage::STATUS_PENDING, $outside->fresh()->status, '起点の外のページはまだ取得されないこと');
    }

    /**
     * config('brand_wheel.crawl_prioritize_origin_scope')=falseで、
     * この依頼の変更前と完全に同じ順序(depth→idのみ)に戻ること。
     */
    public function test_prioritization_can_be_disabled_and_falls_back_to_the_previous_depth_id_order(): void
    {
        config(['brand_wheel.crawl_prioritize_origin_scope' => false]);
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        $outside = $this->seedPending($websiteAnalysis, 'https://example.co.jp/corporate/history.html', depth: 1);
        $within = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/careers.html', depth: 2);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_FETCHED, $outside->fresh()->status, '無効化時はdepthが浅い方(起点の外)が先に取得されること');
        $this->assertSame(AnalysisCrawledPage::STATUS_PENDING, $within->fresh()->status);
    }

    /**
     * 「範囲を狭めるのではなく順序を変えるだけ」の確認: 起点配下を
     * 取り切ったあとも、起点の外のページは失われず、そのまま取得される。
     */
    public function test_pages_outside_the_origin_are_still_fetched_after_the_origin_scope_is_exhausted(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        $outside = $this->seedPending($websiteAnalysis, 'https://example.co.jp/corporate/history.html', depth: 1);
        $within = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/careers.html', depth: 2);

        $this->handle($analysis, $websiteAnalysis); // 1回目: 起点配下を取得
        $this->handle($analysis, $websiteAnalysis); // 2回目: 起点配下が尽きたので外側へ

        $this->assertSame(AnalysisCrawledPage::STATUS_FETCHED, $within->fresh()->status);
        $this->assertSame(AnalysisCrawledPage::STATUS_FETCHED, $outside->fresh()->status, '起点の外のページも読めなくなっていないこと');
    }

    /**
     * pending全件が起点の外にあるサイトでも巡回が止まらないこと
     * (フォールバックが正しく働くこと)。
     */
    public function test_crawl_does_not_stall_when_no_pending_page_is_within_the_origin_scope(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        $this->seedPending($websiteAnalysis, 'https://example.co.jp/corporate/history.html', depth: 1);
        $this->seedPending($websiteAnalysis, 'https://example.co.jp/about/company.html', depth: 1);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(1, AnalysisCrawledPage::query()->where('status', AnalysisCrawledPage::STATUS_FETCHED)->count());
    }

    /**
     * 決定性: 起点配下のpendingが複数あるとき、その中ではdepth→id順を
     * 維持すること(優先度の付け方が順序自体を崩さないこと)。
     */
    public function test_pages_within_the_origin_scope_still_follow_depth_then_id_order_among_themselves(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        $this->seedPending($websiteAnalysis, 'https://example.co.jp/corporate/history.html', depth: 1);
        $deeper = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/deep/page.html', depth: 3);
        $shallower = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/careers.html', depth: 2);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(AnalysisCrawledPage::STATUS_FETCHED, $shallower->fresh()->status, '起点配下どうしではdepthの浅い方が先に取得されること');
        $this->assertSame(AnalysisCrawledPage::STATUS_PENDING, $deeper->fresh()->status);
    }

    /**
     * 同じサイトを2回巡回しても同じ順で取得されること(決定性)。
     */
    public function test_ordering_is_deterministic_across_repeated_crawls_of_the_same_site(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);

        $orders = [];
        for ($run = 0; $run < 2; $run++) {
            [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();
            $this->seedPending($websiteAnalysis, 'https://example.co.jp/corporate/history.html', depth: 1);
            $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/careers.html', depth: 2);

            $this->handle($analysis, $websiteAnalysis);

            $orders[] = AnalysisCrawledPage::query()
                ->where('website_analysis_id', $websiteAnalysis->id)
                ->where('status', AnalysisCrawledPage::STATUS_FETCHED)
                ->pluck('url')
                ->all();
        }

        $this->assertSame($orders[0], $orders[1]);
        $this->assertSame(['https://example.co.jp/recruit/careers.html'], $orders[0]);
    }

    /**
     * 依頼CF追補(2026-09-30、必須修正の再現テスト): 起点配下のページが
     * 1回のchunkに収まらない後方(depthの深い側)にしか無く、かつ手前の
     * chunkが配下の外のページで埋まっているサイトでも、優先が無効化
     * されず配下のページが選ばれること ―― 依頼CFのlimit($scanLimit)方式
     * では、この状況で優先が「静かに」無効化されていた(依頼者指摘)。
     * chunkSizeを小さく設定し、意図的に複数chunkにまたがせて再現する。
     */
    public function test_origin_scoped_pages_beyond_the_first_chunk_are_still_found(): void
    {
        config(['brand_wheel.crawl_origin_priority_chunk_size' => 3]);
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();

        // 1chunk目(3件)を配下の外のページで埋める(depth 1)。
        for ($i = 0; $i < 3; $i++) {
            $this->seedPending($websiteAnalysis, "https://example.co.jp/corporate/page{$i}.html", depth: 1);
        }
        // 起点配下のページは2chunk目以降(depthが深い側)にしか無い。
        $within = $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/careers.html', depth: 2);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(
            AnalysisCrawledPage::STATUS_FETCHED,
            $within->fresh()->status,
            '1chunk目に配下のページが無くても、2chunk目以降を探して見つけること(limit()方式では見つからなかった)',
        );
    }

    /**
     * 依頼CF追補必須: chunkSizeを小さくしても、決定性(同じサイトを2回
     * 巡回すれば同じ順)が崩れないこと。
     */
    public function test_chunking_does_not_break_determinism(): void
    {
        config(['brand_wheel.crawl_origin_priority_chunk_size' => 2]);
        Queue::fake([CrawlWebsitePageJob::class]);

        $orders = [];
        for ($run = 0; $run < 2; $run++) {
            [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();
            for ($i = 0; $i < 4; $i++) {
                $this->seedPending($websiteAnalysis, "https://example.co.jp/corporate/page{$i}.html", depth: 1);
            }
            $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/careers.html', depth: 2);

            $this->handle($analysis, $websiteAnalysis);

            $orders[] = AnalysisCrawledPage::query()
                ->where('website_analysis_id', $websiteAnalysis->id)
                ->where('status', AnalysisCrawledPage::STATUS_FETCHED)
                ->pluck('url')
                ->all();
        }

        $this->assertSame($orders[0], $orders[1]);
        $this->assertSame(['https://example.co.jp/recruit/careers.html'], $orders[0]);
    }

    /**
     * 依頼CF追補必須: 巡回終了ログ(brand_wheel_crawl_completed、依頼CA/CC
     * 由来)に、crawl_prioritize_origin_scopeの値が1項目載ること ――
     * 優先が有効だったかどうかが事後にログだけで分かるようにする。
     */
    public function test_crawl_completed_log_includes_the_prioritize_origin_scope_flag(): void
    {
        // RenderCrawledPageJobもfakeする ―― Http::fake()の応答本文
        // ('<html><body>ok</body></html>')はcrawl_render_candidate_min_chars
        // (200)未満のためrender_candidate扱いになり、fakeしないと
        // AnalyzerClient::render()が実際のHTTPリクエストを試みてしまう
        // (既存のCrawlWebsitePageJobTestと同じ対処)。
        Queue::fake([CrawlWebsitePageJob::class, RenderCrawledPageJob::class]);
        Log::spy();

        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysisWithRecruitOrigin();
        $this->seedPending($websiteAnalysis, 'https://example.co.jp/recruit/careers.html', depth: 1);

        $this->handle($analysis, $websiteAnalysis); // 1件取得。
        $this->handle($analysis, $websiteAnalysis); // pendingが尽き、finalizeCrawl('exhausted')。

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) {
                if ($message !== 'brand_wheel_crawl_completed') {
                    return false;
                }
                $this->assertArrayHasKey('crawl_prioritize_origin_scope', $context);
                $this->assertTrue($context['crawl_prioritize_origin_scope']);

                return true;
            })
            ->atLeast()->once();
    }
}
