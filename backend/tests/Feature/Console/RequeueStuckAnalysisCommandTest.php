<?php

namespace Tests\Feature\Console;

use App\Enums\AnalysisJobStatus;
use App\Enums\AnalysisStatus;
use App\Enums\JobType;
use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\Analysis;
use App\Models\AnalysisJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * analysis:requeue-stuckは「jobsテーブルにStartAnalysisJobが存在しないまま
 * pendingで止まっているAnalysis」だけを対象にした保守コマンド。通常の新規分析は
 * 常駐Workerが自動処理するため、このコマンドは安全条件を満たさない限り
 * 何もしてはならない(Analysis.statusの直接変更・重複dispatchは特に禁止)。
 */
class RequeueStuckAnalysisCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_start_analysis_job_when_all_safety_conditions_are_met(): void
    {
        Queue::fake();

        $analysis = Analysis::factory()->create();

        $this->artisan('analysis:requeue-stuck', ['analysisId' => $analysis->id])
            ->assertExitCode(0);

        Queue::assertPushed(StartAnalysisJob::class, fn ($job) => $job->analysisId === $analysis->id);
        $this->assertSame(AnalysisStatus::Pending, $analysis->refresh()->status);
    }

    public function test_dry_run_reports_safe_without_dispatching(): void
    {
        Queue::fake();

        $analysis = Analysis::factory()->create();

        $this->artisan('analysis:requeue-stuck', ['analysisId' => $analysis->id, '--dry-run' => true])
            ->assertExitCode(0);

        Queue::assertNotPushed(StartAnalysisJob::class);
    }

    public function test_refuses_when_status_is_not_pending(): void
    {
        Queue::fake();

        $analysis = Analysis::factory()->running()->create();

        $this->artisan('analysis:requeue-stuck', ['analysisId' => $analysis->id])
            ->assertExitCode(1);

        Queue::assertNotPushed(StartAnalysisJob::class);
    }

    public function test_refuses_when_started_at_is_already_set(): void
    {
        Queue::fake();

        $analysis = Analysis::factory()->create(['started_at' => now()]);

        $this->artisan('analysis:requeue-stuck', ['analysisId' => $analysis->id])
            ->assertExitCode(1);

        Queue::assertNotPushed(StartAnalysisJob::class);
    }

    public function test_refuses_when_analysis_jobs_already_exist(): void
    {
        Queue::fake();

        $analysis = Analysis::factory()->create();
        AnalysisJob::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => null,
            'job_type' => JobType::StartAnalysis,
            'status' => AnalysisJobStatus::Running,
        ]);

        $this->artisan('analysis:requeue-stuck', ['analysisId' => $analysis->id])
            ->assertExitCode(1);

        Queue::assertNotPushed(StartAnalysisJob::class);
    }

    public function test_refuses_when_a_start_analysis_job_is_already_queued(): void
    {
        $analysis = Analysis::factory()->create();

        // 実際にdispatchして jobs テーブルへ行を作る(Queue::fakeを使わない)。
        // テスト環境の既定キュー接続はsync(即時実行)のため、jobsテーブルへ
        // 実際に積むことを確認するにはdatabase接続を明示する必要がある。
        StartAnalysisJob::dispatch($analysis->id)->onConnection('database')->onQueue('analysis');
        $this->assertDatabaseCount('jobs', 1);

        Queue::fake();

        $this->artisan('analysis:requeue-stuck', ['analysisId' => $analysis->id])
            ->assertExitCode(1);

        Queue::assertNotPushed(StartAnalysisJob::class);
    }

    public function test_refuses_when_a_start_analysis_job_is_in_failed_jobs(): void
    {
        Queue::fake();

        $analysis = Analysis::factory()->create();

        // dispatch自体をQueue::fakeでモックしているため、失敗ペイロードは
        // failed_jobsの実スキーマに沿って直接挿入して再現する。
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'analysis',
            'payload' => json_encode([
                'displayName' => StartAnalysisJob::class,
                'data' => [
                    'commandName' => StartAnalysisJob::class,
                    'command' => serialize(new StartAnalysisJob($analysis->id)),
                ],
            ]),
            'exception' => 'simulated failure',
            'failed_at' => now(),
        ]);

        $this->artisan('analysis:requeue-stuck', ['analysisId' => $analysis->id])
            ->assertExitCode(1);

        Queue::assertNotPushed(StartAnalysisJob::class);
    }

    public function test_fails_for_a_nonexistent_analysis(): void
    {
        $this->artisan('analysis:requeue-stuck', ['analysisId' => 999_999])
            ->assertExitCode(1);
    }
}
