<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\Device;
use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Jobs\Analysis\CaptureScreenshotJob;
use App\Jobs\Analysis\DetectTechnologyJob;
use App\Jobs\Analysis\RunLighthouseJob;
use App\Models\MetricResult;
use App\Models\Screenshot;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnalyzerDrivenJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);
        Storage::fake('analysis');
    }

    private function makeWebsiteAnalysis(): WebsiteAnalysis
    {
        $website = Website::factory()->create(['url' => 'https://example.com', 'normalized_url' => 'https://example.com']);

        return WebsiteAnalysis::factory()->create(['website_id' => $website->id]);
    }

    public function test_lighthouse_job_records_scores_and_stores_raw_report(): void
    {
        Http::fake([
            '*/analyze/lighthouse' => Http::response([
                'success' => true,
                'data' => [
                    'scores' => ['performance' => 80, 'seo' => 90, 'accessibility' => 70, 'best_practices' => 85],
                    'metrics' => ['fcp_ms' => 1200, 'lcp_ms' => 2000, 'cls' => 0.05, 'speed_index_ms' => 1800, 'tbt_ms' => 100, 'inp_ms' => null],
                    'raw_report' => ['categories' => ['performance' => ['score' => 0.8]]],
                ],
            ], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new RunLighthouseJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::RunLighthouse)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        $performance = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'lighthouse_performance'))->first();
        $this->assertSame(MetricResultStatus::Success, $performance->status);
        $this->assertEqualsWithDelta(0.8 * (float) $performance->metricDefinition->max_score, (float) $performance->score, 0.01);

        Storage::disk('analysis')->assertExists("analyses/{$websiteAnalysis->analysis_id}/websites/{$websiteAnalysis->id}/metadata/lighthouse.json");
    }

    public function test_lighthouse_job_leaves_missing_metrics_unavailable_not_zero(): void
    {
        Http::fake([
            '*/analyze/lighthouse' => Http::response([
                'success' => true,
                'data' => ['scores' => ['performance' => null], 'metrics' => []],
            ], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new RunLighthouseJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $performance = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'lighthouse_performance'))->first();
        $this->assertSame(MetricResultStatus::Unavailable, $performance->status);
        $this->assertNull($performance->score);
    }

    public function test_technology_job_records_detected_technologies(): void
    {
        Http::fake([
            '*/analyze/technology' => Http::response([
                'success' => true,
                'data' => ['technologies' => [['name' => 'WordPress', 'category' => 'cms', 'confidence' => 0.9, 'evidence' => ['generator meta tag']]]],
            ], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new DetectTechnologyJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::DetectTechnology)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        $result = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'technology_detected'))->first();
        $this->assertSame(MetricResultStatus::Success, $result->status);
        $this->assertEquals($result->max_score, $result->score);
        $this->assertSame('WordPress', $result->raw_value['technologies'][0]['name']);
    }

    public function test_technology_job_scores_zero_ratio_when_nothing_detected(): void
    {
        Http::fake([
            '*/analyze/technology' => Http::response(['success' => true, 'data' => ['technologies' => []]], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new DetectTechnologyJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $result = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'technology_detected'))->first();
        $this->assertSame(MetricResultStatus::Success, $result->status);
        $this->assertEquals(0.0, (float) $result->score);
    }

    public function test_screenshot_job_stores_metadata_row(): void
    {
        Http::fake([
            '*/analyze/screenshot' => Http::response([
                'success' => true,
                'data' => ['storage_path' => 'analyses/1/websites/1/screenshots/abc.png', 'width' => 1440, 'height' => 1000, 'file_size' => 12345, 'mime_type' => 'image/png'],
            ], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new CaptureScreenshotJob($websiteAnalysis->analysis_id, $websiteAnalysis->id, Device::Desktop))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::CaptureScreenshotDesktop)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        $screenshot = Screenshot::query()->where('website_analysis_id', $websiteAnalysis->id)->where('device', Device::Desktop)->first();
        $this->assertNotNull($screenshot);
        $this->assertSame('analyses/1/websites/1/screenshots/abc.png', $screenshot->storage_path);
        $this->assertSame(1440, $screenshot->width);
    }

    public function test_analyzer_unavailable_marks_job_failed(): void
    {
        Http::fake([
            '*/analyze/lighthouse' => Http::response([], 503),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new RunLighthouseJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::RunLighthouse)->first();
        $this->assertSame(AnalysisJobStatus::Failed, $job->status);
        $this->assertSame('ANALYZER_UNAVAILABLE', $job->error_code);
    }
}
