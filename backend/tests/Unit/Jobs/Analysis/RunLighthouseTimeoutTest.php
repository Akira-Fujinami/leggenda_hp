<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Jobs\Analysis\FinalizeWebsiteAnalysisJob;
use App\Jobs\Analysis\RunLighthouseJob;
use App\Models\AnalysisJob;
use App\Models\MetricResult;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalyzerClient;
use Database\Seeders\CategoryDefinitionSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 2026-07-24の本番障害(RunLighthouseJobが3分でWorkerごとKilledされる)の回帰テスト。
 *
 * 直接原因: RunLighthouseJob::$timeout(180秒)とAnalyzerClientのHTTP timeout(180秒)が
 * 完全に同値だったため、Laravelキュー基盤のジョブtimeout(pcntl_alarm)がHTTP
 * timeoutと競合/先勝ちし、handle()のtry/catchを経由せずWorkerプロセスごと
 * 強制終了していた。
 *
 * 修正: Job timeout(360秒) > AnalyzerClientのHTTP timeout(330秒)の関係を
 * 30秒以上のマージンで固定し、通常はHTTP timeoutが先に発火してAnalysisExceptionと
 * して握りつぶされる(Worker強制終了ではなくJobが正常にfailed終端する)ようにした。
 */
class RunLighthouseTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_timeout_keeps_at_least_a_30_second_margin_over_the_analyzer_http_timeout(): void
    {
        $jobTimeout = (new RunLighthouseJob(1, 1))->timeout;

        $this->assertSame(360, $jobTimeout);
        $this->assertGreaterThanOrEqual(30, $jobTimeout - AnalyzerClient::LIGHTHOUSE_TIMEOUT_SECONDS);
    }

    public function test_recommended_worker_timeout_exceeds_the_job_timeout(): void
    {
        // RenderのDocker Commandへ渡す--timeoutはコード上の値ではないため、
        // ここでは運用上守るべき不等式(Worker --timeout > Job $timeout)の
        // 閾値のみを固定する。実際のRender Worker設定は運用手順書/報告側で管理する。
        $recommendedWorkerTimeoutSeconds = 600;

        $this->assertGreaterThan((new RunLighthouseJob(1, 1))->timeout, $recommendedWorkerTimeoutSeconds);
    }

    private function makeWebsiteAnalysis(): WebsiteAnalysis
    {
        $website = Website::factory()->create(['url' => 'https://example.com', 'normalized_url' => 'https://example.com']);

        return WebsiteAnalysis::factory()->create(['website_id' => $website->id]);
    }

    public function test_analyzer_http_timeout_marks_the_job_failed_instead_of_leaving_it_running(): void
    {
        Http::fake([
            '*/health' => Http::response(['success' => true, 'data' => ['active_contexts' => 0]], 200),
            '*/analyze/lighthouse' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out after 330001 milliseconds');
            },
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new RunLighthouseJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))
            ->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::RunLighthouse)->first();
        $this->assertSame(AnalysisJobStatus::Failed, $job->status);
        $this->assertSame('ANALYZER_UNAVAILABLE', $job->error_code);
    }

    /**
     * analyzer呼び出し失敗時にlighthouse系MetricResultが1件も作られず、
     * 「採点対象の未取得」集計から不可視になっていたバグの回帰テスト。
     */
    public function test_analyzer_failure_marks_lighthouse_metrics_unavailable(): void
    {
        $this->seed(CategoryDefinitionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);

        Http::fake([
            '*/health' => Http::response(['success' => true, 'data' => ['active_contexts' => 0]], 200),
            '*/analyze/lighthouse' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out after 330001 milliseconds');
            },
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new RunLighthouseJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))
            ->handle(app(AnalysisPipeline::class));

        $result = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'lighthouse_performance'))->first();
        $this->assertNotNull($result);
        $this->assertSame(MetricResultStatus::Unavailable, $result->status);
        $this->assertSame('ANALYZER_UNAVAILABLE', $result->error_code);

        $fcp = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'fcp'))->first();
        $this->assertNotNull($fcp);
        $this->assertSame(MetricResultStatus::Unavailable, $fcp->status);
    }

    public function test_queue_level_timeout_kill_still_finalizes_the_website_analysis_when_it_is_the_last_pending_job(): void
    {
        Queue::fake([FinalizeWebsiteAnalysisJob::class]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();

        foreach (JobType::websiteFanOutTypes() as $jobType) {
            AnalysisJob::factory()->create([
                'analysis_id' => $websiteAnalysis->analysis_id,
                'website_analysis_id' => $websiteAnalysis->id,
                'job_type' => $jobType,
                'status' => $jobType === JobType::RunLighthouse ? AnalysisJobStatus::Running : AnalysisJobStatus::Completed,
            ]);
        }

        // Laravelのキュー基盤自身が$timeout超過でジョブを終了させた経路
        // (handle()のtry/catchを経由しない)を再現する。
        $job = new RunLighthouseJob($websiteAnalysis->analysis_id, $websiteAnalysis->id);
        $job->failed(new TimeoutExceededException('App\\Jobs\\Analysis\\RunLighthouseJob has timed out.'));

        $record = $websiteAnalysis->jobs()->where('job_type', JobType::RunLighthouse)->first();
        $this->assertSame(AnalysisJobStatus::Failed, $record->status);
        $this->assertNotNull($record->failed_at);

        // 他の11ジョブは既にCompletedのため、最後の1件がfailedへ確定した
        // 時点でWebsiteAnalysisをfinalizeできる(=partial completionへ進める)。
        Queue::assertPushed(FinalizeWebsiteAnalysisJob::class, 1);

        $this->assertGreaterThan(0, $websiteAnalysis->fresh()->progress);
    }

    public function test_timeout_exception_is_classified_as_job_timeout_not_unknown_error(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis();
        AnalysisJob::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => JobType::RunLighthouse,
            'status' => AnalysisJobStatus::Running,
        ]);

        $job = new RunLighthouseJob($websiteAnalysis->analysis_id, $websiteAnalysis->id);
        $job->failed(new TimeoutExceededException('App\\Jobs\\Analysis\\RunLighthouseJob has timed out.'));

        $record = $websiteAnalysis->jobs()->where('job_type', JobType::RunLighthouse)->first();
        $this->assertSame('JOB_TIMEOUT', $record->error_code);
    }

    public function test_max_attempts_exceeded_exception_is_classified_accordingly(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis();
        AnalysisJob::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => JobType::RunLighthouse,
            'status' => AnalysisJobStatus::Running,
        ]);

        $job = new RunLighthouseJob($websiteAnalysis->analysis_id, $websiteAnalysis->id);
        $job->failed(new MaxAttemptsExceededException('App\\Jobs\\Analysis\\RunLighthouseJob has been attempted too many times.'));

        $record = $websiteAnalysis->jobs()->where('job_type', JobType::RunLighthouse)->first();
        $this->assertSame('MAX_ATTEMPTS_EXCEEDED', $record->error_code);
    }

    public function test_failed_after_timeout_does_not_overwrite_an_already_terminal_job(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = AnalysisJob::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => JobType::RunLighthouse,
            'status' => AnalysisJobStatus::Failed,
            'error_code' => 'LIGHTHOUSE_FAILED',
            'failed_at' => now(),
        ]);

        $job = new RunLighthouseJob($websiteAnalysis->analysis_id, $websiteAnalysis->id);
        $job->failed(new TimeoutExceededException('duplicate timeout callback'));

        $record->refresh();
        $this->assertSame('LIGHTHOUSE_FAILED', $record->error_code);
    }
}
