<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Enums\PageType;
use App\Jobs\Analysis\ReanalyzeRenderedHtmlJob;
use App\Jobs\Analysis\RenderPageJob;
use App\Models\AnalysisPage;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * RenderPageJobの終端(成功・失敗いずれも)からReanalyzeRenderedHtmlJobが
 * 必ず一度だけdispatchされることを確認する
 * (BaseWebsiteAnalysisJob::onWebsiteJobTerminal参照)。
 */
class RenderPageJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsiteAnalysis(): WebsiteAnalysis
    {
        $website = Website::factory()->create(['url' => 'https://example.com', 'normalized_url' => 'https://example.com']);

        return WebsiteAnalysis::factory()->create(['website_id' => $website->id]);
    }

    public function test_successful_render_dispatches_reanalysis_job_once(): void
    {
        Queue::fake([ReanalyzeRenderedHtmlJob::class]);
        Http::fake([
            '*/analyze/render' => Http::response([
                'success' => true,
                'data' => [
                    'html' => '<html><body>Hello</body></html>',
                    'final_url' => 'https://example.com',
                    'http_status' => 200,
                    'load_time_ms' => 120,
                    'fixed_cta' => ['detected' => false, 'text' => null, 'href' => null, 'position' => null],
                ],
            ], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com',
            'page_type' => PageType::Homepage,
        ]);

        (new RenderPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::RenderPage)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        Queue::assertPushed(ReanalyzeRenderedHtmlJob::class, 1);
        Queue::assertPushed(ReanalyzeRenderedHtmlJob::class, fn ($j) => $j->websiteAnalysisId === $websiteAnalysis->id);
    }

    public function test_failed_render_still_dispatches_reanalysis_job_once(): void
    {
        Queue::fake([ReanalyzeRenderedHtmlJob::class]);
        Http::fake([
            '*/analyze/render' => Http::response([], 500),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com',
            'page_type' => PageType::Homepage,
        ]);

        (new RenderPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::RenderPage)->first();
        $this->assertSame(AnalysisJobStatus::Failed, $job->status);

        // レンダリング済みHTMLが存在しない場合でも、ReanalyzeRenderedHtmlJob
        // 自身は無害にno-opするため、必ずdispatchしてよい
        // (放置するとAnalysisJob行がpendingのまま残りFinalizeが止まる)。
        Queue::assertPushed(ReanalyzeRenderedHtmlJob::class, 1);
    }

    public function test_reanalysis_job_is_not_dispatched_prematurely_during_a_retryable_attempt(): void
    {
        // 設計検証で発見したバグの回帰テスト: process()が例外を投げて
        // release()(再試行)される試行では、ReanalyzeRenderedHtmlJobは
        // まだdispatchされてはいけない。canRelease()をtrueにするため、
        // $job->job に実際のQueue Jobをモックして注入する。
        Queue::fake([ReanalyzeRenderedHtmlJob::class]);
        Http::fake([
            '*/analyze/render' => Http::sequence()
                ->push([], 503)
                ->push([
                    'success' => true,
                    'data' => [
                        'html' => '<html><body>Hello</body></html>',
                        'final_url' => 'https://example.com',
                        'http_status' => 200,
                        'load_time_ms' => 120,
                        'fixed_cta' => ['detected' => false, 'text' => null, 'href' => null, 'position' => null],
                    ],
                ], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com',
            'page_type' => PageType::Homepage,
        ]);

        $job = new RenderPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id);
        // canRelease()(=$this->job !== null)をtrueにするため、Laravel標準の
        // FakeJob(release()を実際に記録するだけの安全なテスト用実装)を注入する。
        $job->withFakeQueueInteractions();

        // attempt 1: 503(リトライ可能)→release()されるのみ。この時点では
        // ReanalyzeRenderedHtmlJobはまだdispatchされてはいけない。
        $job->handle(app(AnalysisPipeline::class));

        $job->assertReleased();
        Queue::assertNotPushed(ReanalyzeRenderedHtmlJob::class);

        $recordAfterAttempt1 = $websiteAnalysis->jobs()->where('job_type', JobType::RenderPage)->first();
        $this->assertSame(AnalysisJobStatus::Running, $recordAfterAttempt1->status);

        // attempt 2: 実際のリトライを模して、$job(モック)を持たない新しい
        // インスタンスでhandle()を呼ぶ(canRelease()=falseとなり、成功時は
        // そのままmarkCompleted+終端フックへ進む)。
        $retryJob = new RenderPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id);
        $retryJob->handle(app(AnalysisPipeline::class));

        $recordAfterAttempt2 = $websiteAnalysis->jobs()->where('job_type', JobType::RenderPage)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $recordAfterAttempt2->status);

        // attempt2(実際に成功した試行)の完了後にのみ、正確に1回dispatchされる。
        Queue::assertPushed(ReanalyzeRenderedHtmlJob::class, 1);
    }
}
