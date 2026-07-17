<?php

namespace App\Jobs\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Enums\JobType;
use App\Models\Analysis;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStatusResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Analysis配下の全WebsiteAnalysisが終端状態になった後に、Analysis全体の
 * 最終ステータス(completed/partial/failed)を確定する。
 */
class FinalizeAnalysisJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 30;

    public $uniqueFor = 3600;

    public function __construct(public readonly int $analysisId)
    {
    }

    public function uniqueId(): string
    {
        return "analysis-job:{$this->analysisId}:".JobType::FinalizeAnalysis->value;
    }

    public function handle(AnalysisPipeline $pipeline): void
    {
        $record = $pipeline->markRunning($this->analysisId, null, JobType::FinalizeAnalysis);

        if ($record === null) {
            return;
        }

        try {
            $analysis = Analysis::find($this->analysisId);

            if ($analysis === null) {
                $pipeline->markCompleted($record);

                return;
            }

            $websiteAnalyses = WebsiteAnalysis::query()->where('analysis_id', $this->analysisId)->get();
            $status = app(AnalysisStatusResolver::class)->resolveAnalysisStatus($websiteAnalyses);

            $analysis->update([
                'status' => $status,
                'progress' => 100,
                'completed_at' => now(),
            ]);

            $pipeline->markCompleted($record);
        } catch (\Throwable $e) {
            report($e);
            $pipeline->markFailed($record, AnalysisErrorCode::UnknownError, '分析の集計中に予期しないエラーが発生しました。');
        }
    }
}
