<?php

namespace Tests\Unit\Services\Analysis;

use App\Jobs\Analysis\CaptureScreenshotJob;
use App\Jobs\Analysis\DetectTechnologyJob;
use App\Jobs\Analysis\FinalizeWebsiteAnalysisJob;
use App\Jobs\Analysis\RenderPageJob;
use App\Models\Analysis;
use App\Models\AnalysisJob;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * リード向け簡易分析(Analysis.skip_screenshots=true)がAnalyzerChainから
 * CaptureScreenshotDesktop/Mobileを除外し、かつFinalizeWebsiteAnalysisJobへ
 * 正しく到達することの回帰テスト。skip_screenshots=false(社内向けフル機能・
 * 既定値)の経路は一切変更していないことを併せて確認する
 * (AnalyzerSequentialDispatchTestの既存テスト群がその回帰確認を担う)。
 */
class AnalysisPipelineSkipScreenshotsTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsiteAnalysis(bool $skipScreenshots): WebsiteAnalysis
    {
        $website = Website::factory()->create(['url' => 'https://example.com', 'normalized_url' => 'https://example.com']);
        $analysis = Analysis::factory()->create(['skip_screenshots' => $skipScreenshots]);

        return WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
    }

    public function test_skip_screenshots_true_does_not_register_capture_screenshot_placeholders(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis(skipScreenshots: true);

        app(AnalysisPipeline::class)->registerWebsiteJobPlaceholders($websiteAnalysis);

        $this->assertDatabaseMissing('analysis_jobs', [
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => 'capture_screenshot_desktop',
        ]);
        $this->assertDatabaseMissing('analysis_jobs', [
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => 'capture_screenshot_mobile',
        ]);
        $this->assertDatabaseHas('analysis_jobs', [
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => 'render_page',
        ]);
    }

    public function test_skip_screenshots_false_still_registers_capture_screenshot_placeholders(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis(skipScreenshots: false);

        app(AnalysisPipeline::class)->registerWebsiteJobPlaceholders($websiteAnalysis);

        $this->assertDatabaseHas('analysis_jobs', [
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => 'capture_screenshot_desktop',
        ]);
        $this->assertDatabaseHas('analysis_jobs', [
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => 'capture_screenshot_mobile',
        ]);
    }

    /**
     * maybeFinalizeWebsiteAnalysis()は「必須Job種別が全て終端状態」を条件に
     * するため、RenderPage以外の必須Jobを先に完了済みへしてから検証する
     * (このテストの主眼はスクリーンショット省略の一点であり、他Jobの実際の
     * 実行はAnalyzerDrivenJobsTest等が別途カバーする)。
     */
    private function completeOtherRequiredJobs(WebsiteAnalysis $websiteAnalysis, array $except): void
    {
        AnalysisJob::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->whereNotIn('job_type', $except)
            ->update(['status' => 'completed', 'completed_at' => now()]);
    }

    public function test_skip_screenshots_true_dispatches_technology_directly_after_render_instead_of_screenshot(): void
    {
        Queue::fake([CaptureScreenshotJob::class, DetectTechnologyJob::class]);
        Http::fake(['*/analyze/render' => Http::response(['success' => true, 'data' => ['html' => '<html></html>', 'fixed_cta' => ['detected' => false]]], 200)]);

        $websiteAnalysis = $this->makeWebsiteAnalysis(skipScreenshots: true);
        app(AnalysisPipeline::class)->registerWebsiteJobPlaceholders($websiteAnalysis);
        $this->completeOtherRequiredJobs($websiteAnalysis, ['render_page']);

        (new RenderPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        Queue::assertNotPushed(CaptureScreenshotJob::class);
        Queue::assertPushed(DetectTechnologyJob::class);
    }

    public function test_skip_screenshots_false_still_dispatches_screenshot_after_render_not_technology(): void
    {
        Queue::fake([CaptureScreenshotJob::class, DetectTechnologyJob::class]);
        Http::fake(['*/analyze/render' => Http::response(['success' => true, 'data' => ['html' => '<html></html>', 'fixed_cta' => ['detected' => false]]], 200)]);

        $websiteAnalysis = $this->makeWebsiteAnalysis(skipScreenshots: false);
        app(AnalysisPipeline::class)->registerWebsiteJobPlaceholders($websiteAnalysis);
        $this->completeOtherRequiredJobs($websiteAnalysis, ['render_page']);

        (new RenderPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        Queue::assertPushed(CaptureScreenshotJob::class);
        Queue::assertNotPushed(DetectTechnologyJob::class);
    }

    public function test_skip_screenshots_true_full_chain_reaches_finalize_without_ever_calling_screenshot(): void
    {
        Queue::fake();
        Http::fake([
            '*/analyze/render' => Http::response(['success' => true, 'data' => ['html' => '<html></html>', 'fixed_cta' => ['detected' => false]]], 200),
            '*/analyze/technology' => Http::response(['success' => true, 'data' => ['technologies' => []]], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis(skipScreenshots: true);
        app(AnalysisPipeline::class)->registerWebsiteJobPlaceholders($websiteAnalysis);
        // run_lighthouseはこのテストの主眼(スクリーンショット省略)ではないため、
        // 他の非実行Jobと同様にあらかじめ完了済みにしておく(実際に
        // dispatchされるRunLighthouseJob自体はQueue::fake()により実行されない)。
        $this->completeOtherRequiredJobs($websiteAnalysis, ['render_page', 'detect_technology']);

        $pipeline = app(AnalysisPipeline::class);
        (new RenderPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle($pipeline);
        (new DetectTechnologyJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle($pipeline);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/analyze/screenshot'));
        Queue::assertPushed(FinalizeWebsiteAnalysisJob::class, 1);
    }
}
