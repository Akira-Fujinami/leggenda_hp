<?php

namespace App\Jobs\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Enums\JobType;
use App\Jobs\Analysis\Concerns\LogsJobFailures;
use App\Models\Analysis;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStatusResolver;
use App\Services\Lead\LeadReportDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Analysis配下の全WebsiteAnalysisが終端状態になった後に、Analysis全体の
 * 最終ステータス(completed/partial/failed)を確定する。
 */
class FinalizeAnalysisJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, LogsJobFailures, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 30;

    public $uniqueFor = 3600;

    public function __construct(public readonly int $analysisId) {}

    public function uniqueId(): string
    {
        return "analysis-job:{$this->analysisId}:".JobType::FinalizeAnalysis->value;
    }

    public function handle(AnalysisPipeline $pipeline): void
    {
        $startedAt = microtime(true);

        // StartAnalysisJobと同様、analysis_jobs.analysis_idはanalysesへのFK制約
        // つきのため、親Analysisの存在確認はmarkRunning()(=最初のDB書き込み)より
        // 前に行う。ここで例外を投げると、FK違反がretry/failed_jobsをただ汚染する
        // だけで何も回復しないため、warning logのみのno-opにする。
        $analysis = Analysis::find($this->analysisId);

        if ($analysis === null) {
            Log::warning('Orphaned FinalizeAnalysisJob: parent Analysis not found, skipping', [
                'analysis_id' => $this->analysisId,
                'job_type' => JobType::FinalizeAnalysis->value,
            ]);

            return;
        }

        $record = $pipeline->markRunning($this->analysisId, null, JobType::FinalizeAnalysis);

        if ($record === null) {
            return;
        }

        try {
            $websiteAnalyses = WebsiteAnalysis::query()->where('analysis_id', $this->analysisId)->get();
            $status = app(AnalysisStatusResolver::class)->resolveAnalysisStatus($websiteAnalyses);

            $analysis->update([
                'status' => $status,
                'progress' => 100,
                'completed_at' => now(),
            ]);

            // 依頼Y-3(2026-08-26): 診断がcompleted/partialに到達した時点で
            // レポート生成を起動する(従来はフロントの結果ポーリングが
            // 初めて叩かれたときにしか起動しておらず、構造上「結果画面に
            // 遷移した時点では必ず未生成」だった)。判定条件(reportable判定・
            // Report行の重複作成防止)はLeadReportDispatchServiceへ集約済みで
            // 無改修 ―― LeadAnalysisController::maybeDispatchReportGeneration()
            // からも同じ実装を呼ぶ。
            //
            // この呼び出し自体の失敗が、このJob本来の責務(Analysisの集計)を
            // 失敗扱いにしないよう、独立したtry/catchで囲む
            // (下のcatchに巻き込むとAnalysisErrorCode::UnknownErrorとして
            // 「分析の集計中に予期しないエラー」と誤って記録されてしまう ――
            // 集計自体は成功しているため)。
            try {
                app(LeadReportDispatchService::class)->dispatchIfReportable($analysis);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Failed to dispatch lead report generation from FinalizeAnalysisJob', [
                    'analysis_id' => $this->analysisId,
                    'exception_class' => get_class($e),
                ]);
            }

            $pipeline->markCompleted($record);
        } catch (\Throwable $e) {
            report($e);
            $this->logJobFailure($e, $this->analysisId, null, JobType::FinalizeAnalysis->value, $this->attempts(), microtime(true) - $startedAt);
            $pipeline->markFailed($record, AnalysisErrorCode::UnknownError, '分析の集計中に予期しないエラーが発生しました。');
        }
    }
}
