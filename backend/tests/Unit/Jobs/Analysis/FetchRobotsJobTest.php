<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Jobs\Analysis\FetchRobotsJob;
use App\Models\MetricResult;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FetchRobotsJobTest extends TestCase
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

    public function test_full_score_when_robots_txt_exists_and_parses(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /admin/", 200, ['Content-Type' => 'text/plain']),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new FetchRobotsJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::FetchRobots)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        $result = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'robots_fetched'))->first();
        $this->assertSame(MetricResultStatus::Success, $result->status);
        $this->assertEquals($result->max_score, $result->score);
    }

    public function test_missing_robots_txt_is_still_a_full_score_success(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new FetchRobotsJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $result = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'robots_fetched'))->first();
        $this->assertSame(MetricResultStatus::Success, $result->status);
        $this->assertEquals($result->max_score, $result->score);
    }

    public function test_network_failure_marks_job_failed_and_metric_error(): void
    {
        $website = Website::factory()->create(['url' => 'https://localhost', 'normalized_url' => 'https://localhost']);
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['website_id' => $website->id]);

        (new FetchRobotsJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::FetchRobots)->first();
        $this->assertSame(AnalysisJobStatus::Failed, $job->status);

        $result = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'robots_fetched'))->first();
        $this->assertSame(MetricResultStatus::Error, $result->status);
        $this->assertNull($result->score);
    }
}
