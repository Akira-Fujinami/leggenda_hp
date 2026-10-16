<?php

namespace App\Jobs;

use App\Models\Analysis;
use App\Services\StrengthWeaknessInsight\StrengthWeaknessInsightDispatcher;
use App\Services\StrengthWeaknessInsight\StrengthWeaknessInsightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 依頼DB-6: 比較(Analysis)の強み・弱みの下書きを、Claudeで書く(AIを呼ぶのは1回)。全社のブランド・ホイールの判定が
 * 終わったあと(StrengthWeaknessInsightDispatcher、AnalysisPipeline::maybeFinalizeAnalysis()から)に起動する。
 *
 * このジョブが失敗しても、比較全体は失敗させない ―― AnalysisJobの行を作らず(進捗・完了判定に関わらない)、
 * 例外はここで受けてログに残し、失敗の記録を結果のファイルに置く(依頼CT-1の文になる)。
 * 再実行しても結果のファイルがあればAIは呼ばない。AI呼び出しの再試行はジョブ側では行わない(呼び出し回数を増やさないため)。
 */
class GenerateStrengthWeaknessInsightJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout;

    public $uniqueFor;

    public function __construct(public readonly int $analysisId)
    {
        $this->timeout = ((int) config('strength_weakness_insight.timeout', 90)) * 2 + 30;
        $this->uniqueFor = $this->timeout * 3;
    }

    public function uniqueId(): string
    {
        return "strength-weakness-insight:{$this->analysisId}";
    }

    public function handle(StrengthWeaknessInsightService $service, StrengthWeaknessInsightDispatcher $dispatcher): void
    {
        $analysis = Analysis::find($this->analysisId);

        if ($analysis === null) {
            return;
        }

        $failure = null;

        try {
            $service->generate($analysis);
        } catch (\Throwable $e) {
            Log::error('Strength weakness insight failed', [
                'analysis_id' => $analysis->id,
                'exception' => $e::class,
            ]);
            $failure = $e::class;
        }

        // 成功・失敗のどちらでも、完了を待っていた比較を進める(失敗しても完了にはする)。
        if ($failure !== null) {
            $dispatcher->recordFailureAndFinish((int) $analysis->id, $failure);
        } else {
            $dispatcher->afterFinished((int) $analysis->id);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Strength weakness insight job failed', [
            'analysis_id' => $this->analysisId,
            'exception' => $exception !== null ? $exception::class : null,
        ]);

        // タイムアウト等でhandle()の外で終わらされた場合も、比較の完了を待たせない。
        app(StrengthWeaknessInsightDispatcher::class)->recordFailureAndFinish($this->analysisId, $exception !== null ? $exception::class : 'job_failed');
    }
}
