<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Jobs\Analysis\FetchExternalSeoDataJob;
use App\Models\MetricResult;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Database\Seeders\CategoryDefinitionSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FetchExternalSeoDataJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategoryDefinitionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);
    }

    private function makeWebsiteAnalysis(): WebsiteAnalysis
    {
        $website = Website::factory()->create(['url' => 'https://example.com', 'normalized_url' => 'https://example.com']);

        return WebsiteAnalysis::factory()->create(['website_id' => $website->id]);
    }

    public function test_mock_provider_records_authority_metrics_as_not_applicable(): void
    {
        config(['analysis.seo_provider' => 'mock']);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new FetchExternalSeoDataJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::FetchExternalSeoData)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        $authority = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'authority_score'))->first();
        // モックデータは「本物の評価」として採点対象にしない。
        $this->assertSame(MetricResultStatus::NotApplicable, $authority->status);
        $this->assertNotNull($authority->normalized_value['value']);
        $this->assertSame(0.0, (float) $authority->confidence);
    }

    public function test_semrush_without_api_key_marks_authority_metrics_unavailable_without_failing_the_job(): void
    {
        config(['analysis.seo_provider' => 'semrush', 'services.semrush.api_key' => '']);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new FetchExternalSeoDataJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::FetchExternalSeoData)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        $authority = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'authority_score'))->first();
        $this->assertSame(MetricResultStatus::Unavailable, $authority->status);
        $this->assertSame('SEMRUSH_NOT_CONFIGURED', $authority->error_code);
    }
}
