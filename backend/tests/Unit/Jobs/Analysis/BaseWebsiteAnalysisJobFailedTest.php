<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Jobs\Analysis\FetchRobotsJob;
use App\Jobs\Analysis\FinalizeWebsiteAnalysisJob;
use App\Models\AnalysisJob;
use App\Models\WebsiteAnalysis;
use Illuminate\Database\QueryException;
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

    /**
     * 2026-08-24追加: 8/16〜17の本番障害(positive_impressionカラム欠落による
     * QueryExceptionがJOB_TIMEOUTとして記録され、AIタイムアウト設定の調査へ
     * ミスリードされた)の再発防止。undefined_column(SQLSTATE 42703)は
     * SchemaMismatchとして分類されることを確認する。
     */
    public function test_failed_classifies_undefined_column_query_exception_as_schema_mismatch(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        AnalysisJob::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => JobType::FetchRobots,
            'status' => AnalysisJobStatus::Running,
        ]);

        $previous = new \PDOException('column "positive_impression" does not exist', '42703');
        $queryException = new QueryException('pgsql', 'insert into x (positive_impression) values (?)', [], $previous);

        $job = new FetchRobotsJob($websiteAnalysis->analysis_id, $websiteAnalysis->id);
        $job->failed($queryException);

        $record = AnalysisJob::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('job_type', JobType::FetchRobots)
            ->first();

        $this->assertSame(AnalysisJobStatus::Failed, $record->status);
        $this->assertSame(AnalysisErrorCode::SchemaMismatch->value, $record->error_code);
    }

    /**
     * undefined_column/undefined_table/datatype_mismatch以外のQueryException
     * (デッドロック・接続断等、一過性の可能性がある)はDatabaseErrorとして
     * 分類され、SchemaMismatchとは区別されることを確認する。
     */
    public function test_failed_classifies_other_query_exceptions_as_database_error(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        AnalysisJob::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => JobType::FetchRobots,
            'status' => AnalysisJobStatus::Running,
        ]);

        $previous = new \PDOException('deadlock detected', '40001');
        $queryException = new QueryException('pgsql', 'update x set y = 1', [], $previous);

        $job = new FetchRobotsJob($websiteAnalysis->analysis_id, $websiteAnalysis->id);
        $job->failed($queryException);

        $record = AnalysisJob::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('job_type', JobType::FetchRobots)
            ->first();

        $this->assertSame(AnalysisErrorCode::DatabaseError->value, $record->error_code);
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
