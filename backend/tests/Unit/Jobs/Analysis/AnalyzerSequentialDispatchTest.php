<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\Device;
use App\Jobs\Analysis\CaptureScreenshotJob;
use App\Jobs\Analysis\DetectTechnologyJob;
use App\Jobs\Analysis\RenderPageJob;
use App\Jobs\Analysis\RunLighthouseJob;
use App\Jobs\Analysis\RunRecruitLighthouseJob;
use App\Jobs\GenerateBrandWheelAnalysisJob;
use App\Models\Analysis;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * render→desktop screenshot→mobile screenshot→technology→lighthouseの
 * 順次dispatchカスケード(AnalysisPipeline::ANALYZER_CHAIN)の回帰テスト。
 *
 * 以前はStartAnalysisJobがこれら5ジョブを全て同時にfan-outしており、
 * Analyzer自身のConcurrencyLimiter(既定1)で実行こそ直列化されるものの、
 * Laravel側の複数Worker/複数キューが同時にAnalyzerへHTTPリクエストを
 * 送ること自体は妨げられなかった。低メモリのRender環境で実際に
 * 「Analyzer exceeded its memory limit」(OOM kill)が発生したため
 * (2026-07-25)、1つ前のJobが完全に終端してから次を起動する方式へ変更した。
 */
class AnalyzerSequentialDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsiteAnalysis(): WebsiteAnalysis
    {
        $website = Website::factory()->create(['url' => 'https://example.com', 'normalized_url' => 'https://example.com']);

        return WebsiteAnalysis::factory()->create(['website_id' => $website->id]);
    }

    private function makeWebsiteAnalysisWithBrandWheelEnabled(): WebsiteAnalysis
    {
        $analysis = Analysis::factory()->create(['skip_brand_wheel' => false]);
        $website = Website::factory()->create(['url' => 'https://example.com', 'normalized_url' => 'https://example.com']);

        return WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
    }

    public function test_render_page_job_completion_dispatches_only_the_desktop_screenshot_job_next(): void
    {
        Queue::fake([CaptureScreenshotJob::class, DetectTechnologyJob::class, RunLighthouseJob::class]);
        Http::fake(['*/analyze/render' => Http::response(['success' => true, 'data' => ['html' => '<html></html>', 'fixed_cta' => ['detected' => false]]], 200)]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new RenderPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        Queue::assertPushed(CaptureScreenshotJob::class, fn ($job) => $job->device === Device::Desktop);
        Queue::assertNotPushed(CaptureScreenshotJob::class, fn ($job) => $job->device === Device::Mobile);
        Queue::assertNotPushed(DetectTechnologyJob::class);
        Queue::assertNotPushed(RunLighthouseJob::class);
    }

    public function test_render_page_job_failure_still_dispatches_the_next_analyzer_chain_job(): void
    {
        // 失敗時もチェーンを止めてはいけない ―― 1サイトのrenderが失敗しても、
        // screenshot/technology/lighthouseは独立して試行できる。
        Queue::fake([CaptureScreenshotJob::class]);
        Http::fake(['*/analyze/render' => Http::response([], 500)]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new RenderPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        Queue::assertPushed(CaptureScreenshotJob::class, fn ($job) => $job->device === Device::Desktop);
    }

    /**
     * 2026-08-04: ブランド・ホイール(6軸)分析は、以前はdispatchWebsiteFanOut()
     * からRenderPageJobと並列に起動していたが、レンダリング後HTMLがまだ無い
     * 状態で静的HTMLだけを読んで判定してしまう競合が本番で確認された
     * (BrandWheelAnalysisInputFactoryがrendered_html_pathを優先するように
     * なったことで、この競合が結果に影響するようになった)。RenderPageJobの
     * 終端から必ず起動する形に変更したことを確認する。
     */
    public function test_render_page_job_completion_dispatches_the_brand_wheel_analysis_job(): void
    {
        Queue::fake([CaptureScreenshotJob::class, GenerateBrandWheelAnalysisJob::class]);
        Http::fake(['*/analyze/render' => Http::response(['success' => true, 'data' => ['html' => '<html></html>', 'fixed_cta' => ['detected' => false]]], 200)]);

        $websiteAnalysis = $this->makeWebsiteAnalysisWithBrandWheelEnabled();
        (new RenderPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        Queue::assertPushed(GenerateBrandWheelAnalysisJob::class);
    }

    /**
     * ReanalyzeRenderedHtmlJobと同じく、RenderPageJob自体が失敗しても
     * ブランド・ホイールの起動は妨げられない(その場合は静的HTMLへ
     * フォールバックして判定される、既存の耐障害設計を維持する)。
     */
    public function test_render_page_job_failure_still_dispatches_the_brand_wheel_analysis_job(): void
    {
        Queue::fake([CaptureScreenshotJob::class, GenerateBrandWheelAnalysisJob::class]);
        Http::fake(['*/analyze/render' => Http::response([], 500)]);

        $websiteAnalysis = $this->makeWebsiteAnalysisWithBrandWheelEnabled();
        (new RenderPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        Queue::assertPushed(GenerateBrandWheelAnalysisJob::class);
    }

    public function test_desktop_screenshot_completion_dispatches_only_the_mobile_screenshot_job_next(): void
    {
        Queue::fake([CaptureScreenshotJob::class, DetectTechnologyJob::class, RunLighthouseJob::class]);
        Http::fake(['*/analyze/screenshot' => Http::response(['success' => true, 'data' => ['storage_path' => 'x.jpg', 'width' => 1440, 'height' => 1000, 'file_size' => 1, 'mime_type' => 'image/jpeg']], 200)]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new CaptureScreenshotJob($websiteAnalysis->analysis_id, $websiteAnalysis->id, Device::Desktop))->handle(app(AnalysisPipeline::class));

        Queue::assertPushed(CaptureScreenshotJob::class, fn ($job) => $job->device === Device::Mobile);
        Queue::assertNotPushed(DetectTechnologyJob::class);
        Queue::assertNotPushed(RunLighthouseJob::class);
    }

    public function test_mobile_screenshot_completion_dispatches_only_the_technology_job_next(): void
    {
        Queue::fake([DetectTechnologyJob::class, RunLighthouseJob::class]);
        Http::fake(['*/analyze/screenshot' => Http::response(['success' => true, 'data' => ['storage_path' => 'x.jpg', 'width' => 390, 'height' => 844, 'file_size' => 1, 'mime_type' => 'image/jpeg']], 200)]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new CaptureScreenshotJob($websiteAnalysis->analysis_id, $websiteAnalysis->id, Device::Mobile))->handle(app(AnalysisPipeline::class));

        Queue::assertPushed(DetectTechnologyJob::class);
        Queue::assertNotPushed(RunLighthouseJob::class);
    }

    public function test_technology_completion_dispatches_only_lighthouse_next(): void
    {
        Queue::fake([RunLighthouseJob::class, RunRecruitLighthouseJob::class]);
        Http::fake(['*/analyze/technology' => Http::response(['success' => true, 'data' => ['technologies' => []]], 200)]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new DetectTechnologyJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        Queue::assertPushed(RunLighthouseJob::class);
        Queue::assertNotPushed(RunRecruitLighthouseJob::class);
    }

    /**
     * Phase 3: RunRecruitLighthouseがANALYZER_CHAINの末尾に追加されたため、
     * RunLighthouse自身の終端からも次のチェーン項目(採用ページのLighthouse)を
     * 起動する必要がある(以前はRunLighthouseがチェーンの最後尾だったため、
     * このカスケードは存在しなかった)。
     */
    public function test_lighthouse_completion_dispatches_recruit_lighthouse_next(): void
    {
        Queue::fake([RunRecruitLighthouseJob::class]);
        Http::fake([
            '*/health' => Http::response(['success' => true, 'data' => ['active_contexts' => 0]], 200),
            '*/analyze/lighthouse' => Http::response(['success' => true, 'data' => ['scores' => [], 'metrics' => []]], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new RunLighthouseJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        Queue::assertPushed(RunRecruitLighthouseJob::class);
    }

    public function test_recruit_lighthouse_completion_dispatches_nothing_further(): void
    {
        Queue::fake();
        Http::fake([
            '*/health' => Http::response(['success' => true, 'data' => ['active_contexts' => 0]], 200),
            '*/analyze/lighthouse' => Http::response(['success' => true, 'data' => ['scores' => [], 'metrics' => []]], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new RunRecruitLighthouseJob($websiteAnalysis->analysis_id, $websiteAnalysis->id, 'https://example.com/careers'))->handle(app(AnalysisPipeline::class));

        Queue::assertNothingPushed();
    }

    public function test_lighthouse_defers_with_a_retryable_error_when_the_shared_browser_still_has_active_contexts(): void
    {
        Http::fake([
            '*/health' => Http::response(['success' => true, 'data' => ['active_contexts' => 1, 'queued_sessions' => 0]], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new RunLighthouseJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', 'run_lighthouse')->first();
        $this->assertSame('failed', $job->status->value);
        $this->assertSame('ANALYZER_UNAVAILABLE', $job->error_code);

        // /analyze/lighthouse自体は一度も呼ばれていないはず(健全性チェックで止めたため)。
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/analyze/lighthouse'));
    }

    public function test_lighthouse_proceeds_when_the_health_check_itself_is_unreachable_fail_open(): void
    {
        // ヘルスチェック自体が失敗した場合、Lighthouseの実行を無条件に
        // 妨げてはいけない(Analyzer自身のConcurrencyLimiterが最終的な
        // 排他制御を担うため、fail-openにする)。
        Http::fake([
            '*/health' => function () {
                throw new ConnectionException('cURL error 7: Failed to connect');
            },
            '*/analyze/lighthouse' => Http::response(['success' => true, 'data' => ['scores' => [], 'metrics' => []]], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new RunLighthouseJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', 'run_lighthouse')->first();
        $this->assertSame('completed', $job->status->value);
    }
}
