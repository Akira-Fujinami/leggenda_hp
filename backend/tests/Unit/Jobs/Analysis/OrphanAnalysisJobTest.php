<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Jobs\Analysis\FetchRobotsJob;
use App\Jobs\Analysis\FinalizeAnalysisJob;
use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\AnalysisJob;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 親のAnalysis/WebsiteAnalysisが既に存在しない「孤児Job」
 * (StartAnalysisJob analysis_id=1 analysis_exists=no のようなケース)が、
 * analysis_jobs.analysis_id/website_analysis_idのFK制約に触れて例外を
 * 投げることなく、warning logのみでno-op終了することを確認する。
 *
 * これらのJobのhandle()が先にAnalysisPipeline::markRunning()(=DB書き込み)を
 * 呼んでしまうと、存在しないanalysis_idへのfirstOrCreate()がFK違反例外を
 * 投げ、retry/failed_jobsを汚染し続けるだけで何も回復しない。
 */
class OrphanAnalysisJobTest extends TestCase
{
    use RefreshDatabase;

    private const MISSING_ANALYSIS_ID = 999_999;

    private const MISSING_WEBSITE_ANALYSIS_ID = 888_888;

    public function test_start_analysis_job_is_a_no_op_when_parent_analysis_is_missing(): void
    {
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message, array $context) => $context['analysis_id'] === self::MISSING_ANALYSIS_ID
        );

        (new StartAnalysisJob(self::MISSING_ANALYSIS_ID))->handle(app(AnalysisPipeline::class));

        $this->assertDatabaseCount('analysis_jobs', 0);
    }

    public function test_finalize_analysis_job_is_a_no_op_when_parent_analysis_is_missing(): void
    {
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message, array $context) => $context['analysis_id'] === self::MISSING_ANALYSIS_ID
        );

        (new FinalizeAnalysisJob(self::MISSING_ANALYSIS_ID))->handle(app(AnalysisPipeline::class));

        $this->assertDatabaseCount('analysis_jobs', 0);
    }

    public function test_website_level_job_is_a_no_op_when_parent_website_analysis_is_missing(): void
    {
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message, array $context) => $context['website_analysis_id'] === self::MISSING_WEBSITE_ANALYSIS_ID
        );

        (new FetchRobotsJob(self::MISSING_ANALYSIS_ID, self::MISSING_WEBSITE_ANALYSIS_ID))
            ->handle(app(AnalysisPipeline::class));

        $this->assertDatabaseCount('analysis_jobs', 0);
    }

    /**
     * 親は存在するが対象のAnalysisJob行がまだ無い通常ケースでは、
     * 従来通りmarkRunning()がfirstOrCreate()で行を作成できることの回帰確認
     * (孤児対策の早期returnが正常系を壊していないこと)。
     */
    public function test_website_level_job_still_creates_the_job_row_when_parent_exists(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        (new FetchRobotsJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))
            ->handle(app(AnalysisPipeline::class));

        $record = AnalysisJob::query()
            ->where('analysis_id', $websiteAnalysis->analysis_id)
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->first();

        // 実ネットワークへのfetchが成功するとは限らない環境のため、ここでは
        // 「孤児対策の早期returnに阻まれず行が作成・終端状態まで進むこと」
        // だけを確認する(Completed/Failedいずれでも回帰確認としては十分)。
        $this->assertNotNull($record);
        $this->assertTrue($record->status->isTerminal());
    }
}
