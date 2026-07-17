<?php

namespace Tests\Unit\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\AnalysisStatus;
use App\Enums\JobType;
use App\Enums\WebsiteAnalysisStatus;
use App\Models\AnalysisJob;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisStatusResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalysisStatusResolverTest extends TestCase
{
    use RefreshDatabase;

    private AnalysisStatusResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new AnalysisStatusResolver;
    }

    public function test_website_analysis_is_failed_when_no_usable_result_exists(): void
    {
        $jobs = collect([AnalysisJob::factory()->make(['status' => AnalysisJobStatus::Completed])]);

        $status = $this->resolver->resolveWebsiteAnalysisStatus($jobs, hasUsableResult: false);

        $this->assertSame(WebsiteAnalysisStatus::Failed, $status);
    }

    public function test_website_analysis_is_completed_when_all_jobs_succeeded_and_result_is_usable(): void
    {
        $jobs = collect([
            AnalysisJob::factory()->make(['status' => AnalysisJobStatus::Completed]),
            AnalysisJob::factory()->make(['status' => AnalysisJobStatus::Completed]),
        ]);

        $status = $this->resolver->resolveWebsiteAnalysisStatus($jobs, hasUsableResult: true);

        $this->assertSame(WebsiteAnalysisStatus::Completed, $status);
    }

    public function test_website_analysis_is_partial_when_some_jobs_failed_but_result_is_usable(): void
    {
        $jobs = collect([
            AnalysisJob::factory()->make(['status' => AnalysisJobStatus::Completed]),
            AnalysisJob::factory()->make(['job_type' => JobType::RunLighthouse, 'status' => AnalysisJobStatus::Failed]),
        ]);

        $status = $this->resolver->resolveWebsiteAnalysisStatus($jobs, hasUsableResult: true);

        $this->assertSame(WebsiteAnalysisStatus::Partial, $status);
    }

    public function test_analysis_is_completed_when_all_websites_completed(): void
    {
        $websiteAnalyses = collect([
            WebsiteAnalysis::factory()->make(['status' => WebsiteAnalysisStatus::Completed]),
            WebsiteAnalysis::factory()->make(['status' => WebsiteAnalysisStatus::Completed]),
        ]);

        $this->assertSame(AnalysisStatus::Completed, $this->resolver->resolveAnalysisStatus($websiteAnalyses));
    }

    public function test_analysis_is_failed_when_all_websites_failed(): void
    {
        $websiteAnalyses = collect([
            WebsiteAnalysis::factory()->make(['status' => WebsiteAnalysisStatus::Failed]),
        ]);

        $this->assertSame(AnalysisStatus::Failed, $this->resolver->resolveAnalysisStatus($websiteAnalyses));
    }

    public function test_analysis_is_partial_when_websites_have_mixed_status(): void
    {
        $websiteAnalyses = collect([
            WebsiteAnalysis::factory()->make(['status' => WebsiteAnalysisStatus::Completed]),
            WebsiteAnalysis::factory()->make(['status' => WebsiteAnalysisStatus::Failed]),
        ]);

        $this->assertSame(AnalysisStatus::Partial, $this->resolver->resolveAnalysisStatus($websiteAnalyses));
    }

    public function test_analysis_is_partial_when_a_partial_website_analysis_is_mixed_with_completed(): void
    {
        $websiteAnalyses = collect([
            WebsiteAnalysis::factory()->make(['status' => WebsiteAnalysisStatus::Completed]),
            WebsiteAnalysis::factory()->make(['status' => WebsiteAnalysisStatus::Partial]),
        ]);

        $this->assertSame(AnalysisStatus::Partial, $this->resolver->resolveAnalysisStatus($websiteAnalyses));
    }
}
