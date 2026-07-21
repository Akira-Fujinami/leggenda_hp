<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\Device;
use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Enums\PageType;
use App\Jobs\Analysis\CaptureScreenshotJob;
use App\Jobs\Analysis\DetectTechnologyJob;
use App\Jobs\Analysis\RenderPageJob;
use App\Jobs\Analysis\RunLighthouseJob;
use App\Models\AnalysisPage;
use App\Models\MetricResult;
use App\Models\Screenshot;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Scoring\MetricScorer;
use Database\Seeders\CategoryDefinitionSeeder;
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
        $this->seed(CategoryDefinitionSeeder::class);
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
                    'metrics' => ['fcp_ms' => 1200, 'lcp_ms' => 2000, 'cls' => 0.05, 'speed_index_ms' => 1800, 'tbt_ms' => 100, 'inp_ms' => null, 'request_count' => 42, 'transfer_size_bytes' => 512000],
                    'raw_report' => ['categories' => ['performance' => ['score' => 0.8]]],
                ],
            ], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new RunLighthouseJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::RunLighthouse)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        $performance = MetricResult::query()->with('metricDefinition')->whereHas('metricDefinition', fn ($q) => $q->where('key', 'lighthouse_performance'))->first();
        $this->assertSame(MetricResultStatus::Success, $performance->status);
        $this->assertEquals(80, $performance->normalized_value['value']);

        $outcome = (new MetricScorer)->score($performance->metricDefinition, $performance);
        $this->assertEqualsWithDelta(0.8 * (float) $performance->metricDefinition->max_score, $outcome->score, 0.01);

        $seoScore = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'lighthouse_seo_score'))->first();
        $this->assertSame(MetricResultStatus::Success, $seoScore->status);
        $this->assertEquals(90, $seoScore->normalized_value['value']);

        $requestCount = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'lighthouse_request_count'))->first();
        $this->assertSame(MetricResultStatus::Success, $requestCount->status);
        $this->assertEquals(42, $requestCount->normalized_value['value']);

        $transferSize = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'lighthouse_transfer_size'))->first();
        $this->assertEquals(512000, $transferSize->normalized_value['value']);

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
                'data' => ['technologies' => [
                    ['name' => 'WordPress', 'category' => 'cms', 'confidence' => 0.9, 'evidence' => ['generator meta tag']],
                    ['name' => 'Google Analytics', 'category' => 'analytics', 'confidence' => 0.8, 'evidence' => ['analytics.js']],
                ]],
            ], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new DetectTechnologyJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::DetectTechnology)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        $analytics = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'analytics_configured'))->first();
        $this->assertSame(MetricResultStatus::Success, $analytics->status);
        $this->assertTrue($analytics->normalized_value['value']);

        $cms = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'cms_detected'))->first();
        $this->assertSame(MetricResultStatus::Success, $cms->status);
        $this->assertSame('WordPress', $cms->normalized_value['value']);

        $ga = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'ga_detected'))->first();
        $this->assertTrue($ga->normalized_value['value']);
    }

    public function test_technology_job_marks_analytics_not_found_when_nothing_detected(): void
    {
        Http::fake([
            '*/analyze/technology' => Http::response(['success' => true, 'data' => ['technologies' => []]], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new DetectTechnologyJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $analytics = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'analytics_configured'))->first();
        $this->assertSame(MetricResultStatus::Success, $analytics->status);
        $this->assertFalse($analytics->normalized_value['value']);

        $cms = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'cms_detected'))->first();
        $this->assertSame(MetricResultStatus::NotFound, $cms->status);
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

    public function test_render_job_records_a_detected_fixed_cta(): void
    {
        Http::fake([
            '*/analyze/render' => Http::response([
                'success' => true,
                'data' => [
                    'html' => '<html><body>Hello</body></html>',
                    'final_url' => 'https://example.com',
                    'http_status' => 200,
                    'load_time_ms' => 120,
                    'fixed_cta' => ['detected' => true, 'text' => 'お問い合わせ', 'href' => '/contact', 'position' => 'fixed'],
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

        $fixedCta = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'fixed_cta_present'))->first();
        $this->assertSame(MetricResultStatus::Success, $fixedCta->status);
        $this->assertTrue($fixedCta->normalized_value['value']);
        $this->assertSame('/contact', $fixedCta->raw_value['href']);
    }

    public function test_render_job_records_no_fixed_cta_when_none_is_detected(): void
    {
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

        $fixedCta = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'fixed_cta_present'))->first();
        $this->assertSame(MetricResultStatus::NotFound, $fixedCta->status);
        $this->assertFalse($fixedCta->normalized_value['value']);
    }

    public function test_technology_job_records_all_metrics_as_error_when_detection_fails(): void
    {
        // 「未取得0件なのに技術セクションが空」という矛盾の直接の回帰テスト。
        // 技術検出そのものが失敗した場合、8つの技術系Metricは全てError状態で
        // 記録され(空のまま放置されない)、ジョブ自体は既存通りFailedになる。
        Http::fake([
            '*/analyze/technology' => Http::response([], 500),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new DetectTechnologyJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::DetectTechnology)->first();
        $this->assertSame(AnalysisJobStatus::Failed, $job->status);

        foreach (['analytics_configured', 'cms_detected', 'ga_detected', 'gtm_detected', 'clarity_detected', 'meta_pixel_detected', 'recaptcha_detected', 'cdn_detected'] as $key) {
            $result = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', $key))
                ->where('website_analysis_id', $websiteAnalysis->id)->first();
            $this->assertNotNull($result, "expected a MetricResult row for {$key}");
            $this->assertSame(MetricResultStatus::Error, $result->status);
        }
    }

    public function test_technology_job_does_not_overwrite_an_existing_success_result_on_failure(): void
    {
        // 冪等性・部分成功保持の確認: 過去の試行で既にSuccess記録済みの
        // キーは、後続の失敗によってErrorへ上書きされない。
        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $analyticsDefinition = \App\Models\MetricDefinition::query()->where('key', 'analytics_configured')->firstOrFail();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'metric_definition_id' => $analyticsDefinition->id,
            'status' => MetricResultStatus::Success,
            'normalized_value' => ['value' => true],
        ]);

        Http::fake(['*/analyze/technology' => Http::response([], 500)]);

        (new DetectTechnologyJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $analytics = MetricResult::query()->where('website_analysis_id', $websiteAnalysis->id)
            ->where('metric_definition_id', $analyticsDefinition->id)->first();
        $this->assertSame(MetricResultStatus::Success, $analytics->status);

        $cms = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'cms_detected'))
            ->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertSame(MetricResultStatus::Error, $cms->status);
    }

    public function test_technology_job_updates_a_prior_error_result_to_success_on_a_fresh_analysis_run(): void
    {
        // recordMetricはupdateOrCreateで冪等なため、同一website_analysis_id
        // に対してError記録後に(例えば手動再実行等で)成功データが記録
        // されれば、Errorの行はSuccessへ正しく上書きされる
        // (recordAllErrorのスキップ条件はSuccessのみを保護し、Error自体は
        // 保護しないことの確認)。
        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $cmsDefinition = \App\Models\MetricDefinition::query()->where('key', 'cms_detected')->firstOrFail();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'metric_definition_id' => $cmsDefinition->id,
            'status' => MetricResultStatus::Error,
            'error_message' => '前回の失敗',
        ]);

        Http::fake([
            '*/analyze/technology' => Http::response([
                'success' => true,
                'data' => ['technologies' => [['name' => 'WordPress', 'category' => 'cms', 'confidence' => 0.9, 'evidence' => []]]],
            ], 200),
        ]);

        (new DetectTechnologyJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $cms = MetricResult::query()->where('website_analysis_id', $websiteAnalysis->id)
            ->where('metric_definition_id', $cmsDefinition->id)->first();
        $this->assertSame(MetricResultStatus::Success, $cms->status);
        $this->assertSame('WordPress', $cms->normalized_value['value']);
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
