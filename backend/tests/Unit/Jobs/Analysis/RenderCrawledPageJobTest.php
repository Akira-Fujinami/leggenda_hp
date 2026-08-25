<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\PageType;
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
use App\Services\Analysis\AnalyzerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼D-4: 条件付きレンダリング。CrawlWebsitePageJob::finalizeCrawl()が
 * render_candidate=trueへ選定済みの行を1枚ずつ直列で処理する。
 * AnalyzerClient::render()を直接呼ぶ(RenderPageJobは流用しない)ため、
 * RenderPageJobTestと同じ analyze/render エンドポイントのHttp::fakeパターンを使う。
 */
class RenderCrawledPageJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsiteAnalysis(): array
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

        return [$analysis, $websiteAnalysis];
    }

    private function makeCandidate(WebsiteAnalysis $websiteAnalysis, string $url, int $depth = 1): AnalysisCrawledPage
    {
        $page = new AnalysisCrawledPage;
        $page->website_analysis_id = $websiteAnalysis->id;
        $page->url = $url;
        $page->depth = $depth;
        $page->discovered_via = 'link';
        $page->status = AnalysisCrawledPage::STATUS_FETCHED;
        $page->render_candidate = true;
        $page->save();

        return $page;
    }

    private function handle(Analysis $analysis, WebsiteAnalysis $websiteAnalysis): void
    {
        (new RenderCrawledPageJob($analysis->id, $websiteAnalysis->id))->handle(
            app(AnalysisPipeline::class),
            app(AnalyzerClient::class),
            app(AnalysisStoragePaths::class),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
    }

    /**
     * レンダリング成功時: rendered_html_pathが保存され、render_candidateが
     * falseに落ち、次のRenderCrawledPageJobがdispatchされる。
     */
    public function test_renders_a_candidate_and_dispatches_the_next_job(): void
    {
        Queue::fake([RenderCrawledPageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $candidate = $this->makeCandidate($websiteAnalysis, 'https://example.co.jp/short');

        Http::fake([
            '*/analyze/render' => Http::response([
                'success' => true,
                'data' => ['html' => '<html><body>rendered</body></html>', 'navigation_status' => 'ok'],
            ], 200),
        ]);

        $this->handle($analysis, $websiteAnalysis);

        $fresh = $candidate->fresh();
        $this->assertFalse($fresh->render_candidate);
        $this->assertNotNull($fresh->rendered_html_path);
        $this->assertSame('<html><body>rendered</body></html>', Storage::disk('analysis')->get($fresh->rendered_html_path));

        Queue::assertPushed(RenderCrawledPageJob::class, 1);
        $this->assertSame(0, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }

    /**
     * レンダリング失敗時: 静的HTMLへフォールバックする(rendered_html_pathは
     * 設定しない)。render_candidateはfalseに落とし、二度と再試行しない
     * (連鎖は継続する)。
     */
    public function test_render_failure_falls_back_to_static_html_and_continues_the_chain(): void
    {
        Queue::fake([RenderCrawledPageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $candidate = $this->makeCandidate($websiteAnalysis, 'https://example.co.jp/short');

        Http::fake([
            '*/analyze/render' => Http::response([], 500),
        ]);

        $this->handle($analysis, $websiteAnalysis);

        $fresh = $candidate->fresh();
        $this->assertFalse($fresh->render_candidate);
        $this->assertNull($fresh->rendered_html_path);

        Queue::assertPushed(RenderCrawledPageJob::class, 1);
    }

    /**
     * 候補が残っていない場合、ブランド・ホイール分析を直接起動し、
     * RenderCrawledPageJobはこれ以上dispatchしない。
     */
    public function test_no_remaining_candidates_dispatches_brand_wheel_directly(): void
    {
        Queue::fake([RenderCrawledPageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();

        $this->handle($analysis, $websiteAnalysis);

        Http::assertNothingSent();
        Queue::assertNotPushed(RenderCrawledPageJob::class);
        $this->assertSame(1, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }

    /**
     * 同時実行数の制限(1枚ずつ直列): 深さの浅い順に1件だけ処理し、他の
     * 候補は未処理のまま残す。過去にOOMの経緯があるための制約(依頼D-4)。
     */
    public function test_processes_only_one_candidate_per_invocation_in_depth_order(): void
    {
        Queue::fake([RenderCrawledPageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $deep = $this->makeCandidate($websiteAnalysis, 'https://example.co.jp/deep', depth: 2);
        $shallow = $this->makeCandidate($websiteAnalysis, 'https://example.co.jp/shallow', depth: 1);

        Http::fake([
            '*/analyze/render' => Http::response([
                'success' => true,
                'data' => ['html' => '<html><body>rendered</body></html>'],
            ], 200),
        ]);

        $this->handle($analysis, $websiteAnalysis);

        Http::assertSentCount(1);
        $this->assertFalse($shallow->fresh()->render_candidate);
        $this->assertTrue($deep->fresh()->render_candidate);
        Queue::assertPushed(RenderCrawledPageJob::class, 1);
    }

    /**
     * 依頼M-1: レンダリングの進行に応じてRenderCrawledPagesのAnalysisJob.
     * progressが増加すること。totalCandidatesはfinalizeCrawl()が
     * dispatch時に渡す値を模擬する(このテストではRenderCrawledPageJobを
     * 直接構築するため、コンストラクタで明示的に渡す)。
     */
    public function test_analysis_job_progress_increases_as_candidates_are_rendered(): void
    {
        Queue::fake([RenderCrawledPageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $this->makeCandidate($websiteAnalysis, 'https://example.co.jp/one', depth: 1);
        $this->makeCandidate($websiteAnalysis, 'https://example.co.jp/two', depth: 2);
        app(AnalysisPipeline::class)->markRunning($analysis->id, $websiteAnalysis->id, \App\Enums\JobType::RenderCrawledPages);

        Http::fake([
            '*/analyze/render' => Http::response([
                'success' => true,
                'data' => ['html' => '<html><body>rendered</body></html>'],
            ], 200),
        ]);

        (new RenderCrawledPageJob($analysis->id, $websiteAnalysis->id, totalCandidates: 2))->handle(
            app(AnalysisPipeline::class),
            app(AnalyzerClient::class),
            app(AnalysisStoragePaths::class),
        );

        $job = \App\Models\AnalysisJob::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('job_type', \App\Enums\JobType::RenderCrawledPages)
            ->first();
        $this->assertSame(50, $job->progress);
        $this->assertSame(\App\Enums\AnalysisJobStatus::Running, $job->status);
        $this->assertGreaterThan(0, $websiteAnalysis->fresh()->progress);

        (new RenderCrawledPageJob($analysis->id, $websiteAnalysis->id, totalCandidates: 2))->handle(
            app(AnalysisPipeline::class),
            app(AnalyzerClient::class),
            app(AnalysisStoragePaths::class),
        );

        $this->assertSame(99, $job->fresh()->progress);
    }

    /**
     * 順序保証(依頼D-4): 候補が残っている間はブランド・ホイール分析は
     * 起動されない。最後の候補が処理された後の連鎖でのみ起動される。
     */
    public function test_brand_wheel_is_not_dispatched_while_render_candidates_remain(): void
    {
        Queue::fake([RenderCrawledPageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $this->makeCandidate($websiteAnalysis, 'https://example.co.jp/one', depth: 1);
        $this->makeCandidate($websiteAnalysis, 'https://example.co.jp/two', depth: 2);

        Http::fake([
            '*/analyze/render' => Http::response([
                'success' => true,
                'data' => ['html' => '<html><body>rendered</body></html>'],
            ], 200),
        ]);

        $this->handle($analysis, $websiteAnalysis);
        $this->assertSame(0, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());

        // 連鎖の次のジョブを模擬する。
        $this->handle($analysis, $websiteAnalysis);
        $this->assertSame(0, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());

        // 3回目でようやく候補が尽き、ブランド・ホイール分析が起動される。
        $this->handle($analysis, $websiteAnalysis);
        $this->assertSame(1, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }

    /**
     * トップページの`analysis_pages`行を一切上書きしない
     * (RenderPageJobを流用していないことの確認、依頼者指摘)。
     */
    public function test_never_touches_the_homepage_analysis_pages_row(): void
    {
        Queue::fake([RenderCrawledPageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $this->makeCandidate($websiteAnalysis, 'https://example.co.jp/short');

        Http::fake([
            '*/analyze/render' => Http::response([
                'success' => true,
                'data' => ['html' => '<html><body>rendered</body></html>'],
            ], 200),
        ]);

        $this->handle($analysis, $websiteAnalysis);

        $homepage = AnalysisPage::query()->where('website_analysis_id', $websiteAnalysis->id)->where('page_type', PageType::Homepage)->first();
        $this->assertNull($homepage->rendered_html_path);
        $this->assertSame(1, AnalysisPage::query()->where('website_analysis_id', $websiteAnalysis->id)->where('page_type', PageType::Homepage)->count());
    }

    /**
     * failed()経路: 残りの候補をスキップし、ブランド・ホイール分析を
     * 直接起動する(不安定なanalyzerへの再試行連打を避ける保守的な選択、
     * 依頼D-2の終端保証をこのJobでも満たす)。
     */
    public function test_failed_handler_dispatches_brand_wheel_directly_and_skips_remaining_candidates(): void
    {
        Queue::fake([RenderCrawledPageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $this->makeCandidate($websiteAnalysis, 'https://example.co.jp/one');
        $this->makeCandidate($websiteAnalysis, 'https://example.co.jp/two');

        (new RenderCrawledPageJob($analysis->id, $websiteAnalysis->id))->failed(new \RuntimeException('boom'));

        Queue::assertNotPushed(RenderCrawledPageJob::class);
        $this->assertSame(1, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }
}
