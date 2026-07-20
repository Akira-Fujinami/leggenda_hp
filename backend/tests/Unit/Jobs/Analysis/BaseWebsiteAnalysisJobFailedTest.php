<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Jobs\Analysis\FetchRobotsJob;
use App\Jobs\Analysis\FinalizeWebsiteAnalysisJob;
use App\Models\AnalysisJob;
use App\Models\WebsiteAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Laravelのキュー基盤がジョブの$timeout超過や再試行の使い果たしによって
 * handle()のtry/catchを経由せず直接ジョブを終了させた場合、failed()経由でも
 * AnalysisJobがきちんとFailedへ遷移し、後続の確定処理が止まらないことを確認する。
 * これを怠ると、AnalysisJobが「running」のまま永久に残り、
 * maybeFinalizeWebsiteAnalysis()の「全Job終端待ち」が完了しなくなる。
 */
class BaseWebsiteAnalysisJobFailedTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_marks_the_stuck_job_row_as_failed(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        AnalysisJob::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => JobType::FetchRobots,
            'status' => AnalysisJobStatus::Running,
        ]);

        $job = new FetchRobotsJob($websiteAnalysis->analysis_id, $websiteAnalysis->id);
        $job->failed(new \RuntimeException('simulated queue-level timeout'));

        $record = AnalysisJob::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('job_type', JobType::FetchRobots)
            ->first();

        $this->assertSame(AnalysisJobStatus::Failed, $record->status);
        $this->assertNotNull($record->failed_at);
    }

    public function test_failed_still_triggers_finalize_when_it_is_the_last_terminal_job(): void
    {
        Queue::fake([FinalizeWebsiteAnalysisJob::class]);

        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        foreach (JobType::websiteFanOutTypes() as $jobType) {
            AnalysisJob::factory()->create([
                'analysis_id' => $websiteAnalysis->analysis_id,
                'website_analysis_id' => $websiteAnalysis->id,
                'job_type' => $jobType,
                'status' => $jobType === JobType::FetchRobots ? AnalysisJobStatus::Running : AnalysisJobStatus::Completed,
            ]);
        }

        $job = new FetchRobotsJob($websiteAnalysis->analysis_id, $websiteAnalysis->id);
        $job->failed(new \RuntimeException('simulated queue-level timeout'));

        Queue::assertPushed(FinalizeWebsiteAnalysisJob::class, 1);
    }

    public function test_failed_is_a_no_op_when_the_job_already_reached_a_terminal_status(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        AnalysisJob::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => JobType::FetchRobots,
            'status' => AnalysisJobStatus::Completed,
        ]);

        $job = new FetchRobotsJob($websiteAnalysis->analysis_id, $websiteAnalysis->id);
        $job->failed(new \RuntimeException('should not overwrite a completed job'));

        $record = AnalysisJob::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('job_type', JobType::FetchRobots)
            ->first();

        $this->assertSame(AnalysisJobStatus::Completed, $record->status);
    }
}
