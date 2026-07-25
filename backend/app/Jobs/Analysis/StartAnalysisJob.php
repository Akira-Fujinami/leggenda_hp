<?php

namespace App\Jobs\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Enums\AnalysisStatus;
use App\Enums\JobType;
use App\Models\Analysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Analysisの起動。対象WebsiteAnalysis行は既にAnalysisService::start()が
 * 同期的に作成済み(「どのサイトを対象にするか」はHTTPリクエスト時点で確定させる)。
 * このジョブの責務は、サイトごとのAnalysisJobプレースホルダ登録と
 * ファンアウト起動のみ。
 */
class StartAnalysisJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 30;

    public $uniqueFor = 600;

    public function __construct(public readonly int $analysisId)
    {
    }

    public function uniqueId(): string
    {
        return "analysis-job:{$this->analysisId}:".JobType::StartAnalysis->value;
    }

    public function handle(AnalysisPipeline $pipeline): void
    {
        // analysis_jobs.analysis_idはanalysesへのFK(制約あり)のため、親Analysisが
        // 既に存在しない(例: テスト/開発環境のDBリセット後に残った孤児Job)状態で
        // markRunning()を呼ぶとfirstOrCreate()がFK違反例外を投げてしまう。
        // 例外経由のretry/failed_jobs汚染を避けるため、DB書き込みの前に必ず
        // 親の存在を確認し、無ければ例外を投げずwarning logのみでno-op終了する。
        $analysis = Analysis::with('websiteAnalyses')->find($this->analysisId);

        if ($analysis === null) {
            Log::warning('Orphaned StartAnalysisJob: parent Analysis not found, skipping', [
                'analysis_id' => $this->analysisId,
                'job_type' => JobType::StartAnalysis->value,
            ]);

            return;
        }

        $record = $pipeline->markRunning($this->analysisId, null, JobType::StartAnalysis);

        if ($record === null) {
            return;
        }

        try {
            $analysis->update(['status' => AnalysisStatus::Running, 'started_at' => now()]);

            foreach ($analysis->websiteAnalyses as $websiteAnalysis) {
                $pipeline->registerWebsiteJobPlaceholders($websiteAnalysis);
            }

            foreach ($analysis->websiteAnalyses as $websiteAnalysis) {
                $pipeline->dispatchWebsiteFanOut($websiteAnalysis);
            }

            $pipeline->markCompleted($record);
        } catch (\Throwable $e) {
            report($e);
            $pipeline->markFailed($record, AnalysisErrorCode::UnknownError, '分析の開始中に予期しないエラーが発生しました。');
        }
    }
}
