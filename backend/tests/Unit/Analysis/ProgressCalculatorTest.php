<?php

namespace Tests\Unit\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Models\AnalysisJob;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\ProgressCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgressCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private ProgressCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new ProgressCalculator;
    }

    private function job(JobType $type, AnalysisJobStatus $status): AnalysisJob
    {
        return AnalysisJob::factory()->make(['job_type' => $type, 'status' => $status]);
    }

    public function test_it_returns_zero_when_no_jobs_are_done(): void
    {
        $jobs = collect([$this->job(JobType::FetchStaticPage, AnalysisJobStatus::Pending)]);

        $this->assertSame(0, $this->calculator->forWebsiteAnalysis($jobs));
    }

    public function test_it_sums_weights_of_completed_jobs(): void
    {
        $jobs = collect([
            $this->job(JobType::FetchStaticPage, AnalysisJobStatus::Completed), // 15
            $this->job(JobType::FetchRobots, AnalysisJobStatus::Completed), // 5
            $this->job(JobType::RunLighthouse, AnalysisJobStatus::Running), // not counted
        ]);

        $this->assertSame(20, $this->calculator->forWebsiteAnalysis($jobs));
    }

    public function test_failed_jobs_still_count_toward_progress(): void
    {
        $jobs = collect([
            $this->job(JobType::FetchStaticPage, AnalysisJobStatus::Failed), // 15, counted as "done"
        ]);

        $this->assertSame(15, $this->calculator->forWebsiteAnalysis($jobs));
    }

    public function test_all_website_level_jobs_completed_reaches_100(): void
    {
        $jobs = collect(JobType::websiteLevelTypes())
            ->map(fn ($type) => $this->job($type, AnalysisJobStatus::Completed));

        $this->assertSame(100, $this->calculator->forWebsiteAnalysis($jobs));
    }

    public function test_analysis_progress_is_average_of_website_analyses(): void
    {
        $websiteAnalyses = collect([
            WebsiteAnalysis::factory()->make(['progress' => 100]),
            WebsiteAnalysis::factory()->make(['progress' => 50]),
        ]);

        $this->assertSame(75, $this->calculator->forAnalysis($websiteAnalyses));
    }

    public function test_analysis_progress_is_zero_when_no_website_analyses(): void
    {
        $this->assertSame(0, $this->calculator->forAnalysis(collect()));
    }
}
