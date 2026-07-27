<?php

namespace Tests\Unit\Services\Analysis;

use App\Enums\Device;
use App\Jobs\Analysis\CaptureScreenshotJob;
use App\Jobs\Analysis\DetectTechnologyJob;
use App\Jobs\Analysis\FinalizeWebsiteAnalysisJob;
use App\Jobs\Analysis\RenderPageJob;
use App\Jobs\Analysis\RunLighthouseJob;
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
 * リード向け簡易分析(Analysis.skip_lighthouse=true)がAnalyzerChainから
 * RunLighthouseを除外し、かつFinalizeWebsiteAnalysisJobへ正しく到達することの
 * 回帰テスト。skip_lighthouse=false(社内向けフル機能・既定値)の経路は
 * 一切変更していないことを併せて確認する
 * (AnalyzerSequentialDispatchTestの既存テスト群がその回帰確認を担う)。
 */
class AnalysisPipelineSkipLighthouseTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsiteAnalysis(bool $skipLighthouse): WebsiteAnalysis
    {
        $website = Website::factory()->create(['url' => 'https://example.com', 'normalized_url' => 'https://example.com']);
        $analysis = Analysis::factory()->create(['skip_lighthouse' => $skipLighthouse]);

        return WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
    }

    public function test_skip_lighthouse_true_does_not_register_a_run_lighthouse_placeholder(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis(skipLighthouse: true);

        app(AnalysisPipeline::class)->registerWebsiteJobPlaceholders($websiteAnalysis);

        $this->assertDatabaseMissing('analysis_jobs', [
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => 'run_lighthouse',
        ]);
        $this->assertDatabaseHas('analysis_jobs', [
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => 'detect_technology',
        ]);
    }

    public function test_skip_lighthouse_false_still_registers_a_run_lighthouse_placeholder(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis(skipLighthouse: false);

        app(AnalysisPipeline::class)->registerWebsiteJobPlaceholders($websiteAnalysis);

        $this->assertDatabaseHas('analysis_jobs', [
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => 'run_lighthouse',
        ]);
    }

    /**
     * maybeFinalizeWebsiteAnalysis()は「必須Job種別が全て終端状態」を条件に
     * するため、DetectTechnology以外の必須Jobを先に完了済みへしてから
     * 検証する(このテストの主眼はLighthouse省略の一点であり、他Jobの
     * 実際の実行はAnalyzerDrivenJobsTest等が別途カバーする)。
     */
    private function completeOtherRequiredJobs(WebsiteAnalysis $websiteAnalysis, array $except): void
    {
        AnalysisJob::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->whereNotIn('job_type', $except)
            ->update(['status' => 'completed', 'completed_at' => now()]);
    }

    public function test_skip_lighthouse_true_dispatches_finalize_directly_after_technology_instead_of_lighthouse(): void
    {
        Queue::fake([RunLighthouseJob::class, FinalizeWebsiteAnalysisJob::class]);
        Http::fake(['*/analyze/technology' => Http::response(['success' => true, 'data' => ['technologies' => []]], 200)]);

        $websiteAnalysis = $this->makeWebsiteAnalysis(skipLighthouse: true);
        app(AnalysisPipeline::class)->registerWebsiteJobPlaceholders($websiteAnalysis);
        $this->completeOtherRequiredJobs($websiteAnalysis, ['detect_technology']);

        (new DetectTechnologyJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        Queue::assertNotPushed(RunLighthouseJob::class);
        Queue::assertPushed(FinalizeWebsiteAnalysisJob::class);
    }

    public function test_skip_lighthouse_false_still_dispatches_lighthouse_after_technology_not_finalize(): void
    {
        Queue::fake([RunLighthouseJob::class, FinalizeWebsiteAnalysisJob::class]);
        Http::fake(['*/analyze/technology' => Http::response(['success' => true, 'data' => ['technologies' => []]], 200)]);

        $websiteAnalysis = $this->makeWebsiteAnalysis(skipLighthouse: false);
        app(AnalysisPipeline::class)->registerWebsiteJobPlaceholders($websiteAnalysis);

        (new DetectTechnologyJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        Queue::assertPushed(RunLighthouseJob::class);
        Queue::assertNotPushed(FinalizeWebsiteAnalysisJob::class);
    }

    public function test_skip_lighthouse_true_full_chain_reaches_finalize_without_ever_calling_lighthouse(): void
    {
        Queue::fake();
        Http::fake([
            '*/analyze/render' => Http::response(['success' => true, 'data' => ['html' => '<html></html>', 'fixed_cta' => ['detected' => false]]], 200),
            '*/analyze/screenshot' => Http::response(['success' => true, 'data' => ['storage_path' => 'x.jpg', 'width' => 100, 'height' => 100, 'file_size' => 1, 'mime_type' => 'image/jpeg']], 200),
            '*/analyze/technology' => Http::response(['success' => true, 'data' => ['technologies' => []]], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis(skipLighthouse: true);
        app(AnalysisPipeline::class)->registerWebsiteJobPlaceholders($websiteAnalysis);

        $pipeline = app(AnalysisPipeline::class);
        (new RenderPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle($pipeline);
        (new CaptureScreenshotJob($websiteAnalysis->analysis_id, $websiteAnalysis->id, Device::Desktop))->handle($pipeline);
        (new CaptureScreenshotJob($websiteAnalysis->analysis_id, $websiteAnalysis->id, Device::Mobile))->handle($pipeline);
        $this->completeOtherRequiredJobs($websiteAnalysis, ['render_page', 'capture_screenshot_desktop', 'capture_screenshot_mobile', 'detect_technology']);
        (new DetectTechnologyJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle($pipeline);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/analyze/lighthouse'));
        Queue::assertPushed(FinalizeWebsiteAnalysisJob::class, 1);
    }
}
