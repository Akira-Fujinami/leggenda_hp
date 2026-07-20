<?php

namespace Tests\Unit\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Models\AnalysisJob;
use App\Services\Analysis\JobStatusSummarizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobStatusSummarizerTest extends TestCase
{
    use RefreshDatabase;

    private JobStatusSummarizer $summarizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->summarizer = new JobStatusSummarizer;
    }

    private function job(AnalysisJobStatus $status): AnalysisJob
    {
        return AnalysisJob::factory()->make(['status' => $status]);
    }

    public function test_it_counts_each_status_and_computes_finished(): void
    {
        $jobs = collect([
            $this->job(AnalysisJobStatus::Completed),
            $this->job(AnalysisJobStatus::Completed),
            $this->job(AnalysisJobStatus::Failed),
            $this->job(AnalysisJobStatus::Running),
            $this->job(AnalysisJobStatus::Pending),
        ]);

        $summary = $this->summarizer->summarize($jobs);

        $this->assertSame(5, $summary['total']);
        $this->assertSame(2, $summary['completed']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $summary['running']);
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(0, $summary['skipped']);
        // finished = completed + failed + skipped = 2 + 1 + 0 = 3 (runningとpendingは含まない)
        $this->assertSame(3, $summary['finished']);
    }

    public function test_failed_jobs_count_toward_finished_not_just_completed(): void
    {
        $jobs = collect([
            $this->job(AnalysisJobStatus::Failed),
            $this->job(AnalysisJobStatus::Failed),
        ]);

        $summary = $this->summarizer->summarize($jobs);

        $this->assertSame(2, $summary['finished']);
        $this->assertSame(0, $summary['completed']);
    }

    public function test_it_handles_an_empty_job_collection(): void
    {
        $summary = $this->summarizer->summarize(collect());

        $this->assertSame(0, $summary['total']);
        $this->assertSame(0, $summary['finished']);
    }
}
