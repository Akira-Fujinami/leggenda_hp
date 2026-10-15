<?php

namespace App\Services\StrengthWeaknessSentence;

use App\Enums\AnalysisKind;
use App\Jobs\GenerateStrengthWeaknessSentenceJob;
use App\Models\Analysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Support\Facades\Log;

/**
 * 依頼CV-5: 比較(AdminComparison)の全社のブランド・ホイールの判定が終わったあとに、強み・弱みの一文を書くジョブを
 * 1回だけ起動し、そのジョブが終わるまで診断全体(Analysis)の完了を待たせる(依頼CQ追補 CQA-5・依頼CU-4と同じ考え方)。
 * AnalysisPipeline::maybeFinalizeAnalysis()が、全社(WebsiteAnalysis)が終端状態になったあとにここへ聞く。
 * 依頼CUのジョブ(ComparisonCommentaryDispatcher)は、これに置き換えた(コードは残してあるが、比較の流れからは呼ばない)。
 *
 * 待つのは次のすべてを満たすとき:
 *  - config('strength_weakness_sentence.enabled')がtrue(falseなら何もしない ―― 既定)
 *  - 比較(AdminComparison)で、ブランド・ホイールを行う(skip_brand_wheelでない)
 *  - 結果のファイルがまだ無い(ジョブが失敗しても、失敗の記録が結果のファイルとして置かれるため、待ちは解ける)
 *  - 全社の判定が終わってから、config('strength_weakness_sentence.wait_max_minutes')を過ぎていない
 *    (ジョブが永久に終わらないときに、比較を完了できなくならないための保険)
 *
 * 起動や記録で起きた例外は握って警告ログにするだけにする ―― 失敗しても比較全体は失敗させない
 * (依頼CT-1の文になるだけ)。
 */
class StrengthWeaknessSentenceDispatcher
{
    public function __construct(
        private readonly StrengthWeaknessSentenceStore $store,
        private readonly StrengthWeaknessSentenceService $service,
    ) {}

    /**
     * 診断全体の完了を待たせるか。待たせる場合は、ジョブを起動する(起動済みなら二重には起動されない)。
     */
    public function holdFinalization(Analysis $analysis): bool
    {
        if (! (bool) config('strength_weakness_sentence.enabled', false)) {
            return false;
        }

        if ($analysis->kind !== AnalysisKind::AdminComparison || $analysis->skip_brand_wheel === true) {
            return false;
        }

        try {
            if ($this->store->exists((int) $analysis->id)) {
                return false;
            }

            $latest = $analysis->websiteAnalyses()->max('updated_at');
            if ($latest !== null && now()->parse($latest)->lt(now()->subMinutes((int) config('strength_weakness_sentence.wait_max_minutes', 10)))) {
                return false;
            }

            GenerateStrengthWeaknessSentenceJob::dispatch((int) $analysis->id)->onQueue('ai');

            return true;
        } catch (\Throwable $e) {
            Log::warning('Strength weakness sentence dispatch failed', [
                'analysis_id' => $analysis->id,
                'exception' => $e::class,
            ]);

            $this->recordFailureAndFinish((int) $analysis->id, 'dispatch_failed');

            return false;
        }
    }

    /**
     * ジョブの失敗(例外・タイムアウト)を、結果のファイルとして記録して、比較の完了を進める。
     * 失敗しても完了にはする ―― 依頼CT-1の文になるだけ。
     */
    public function recordFailureAndFinish(int $analysisId, string $reason): void
    {
        try {
            $analysis = Analysis::query()->find($analysisId);
            if ($analysis !== null && ! $this->store->exists($analysisId)) {
                $this->service->recordFailure($analysis, $reason);
            }
        } catch (\Throwable $e) {
            Log::error('Strength weakness sentence failure could not be recorded', [
                'analysis_id' => $analysisId,
                'exception' => $e::class,
            ]);
        }

        try {
            $this->afterFinished($analysisId);
        } catch (\Throwable $e) {
            Log::error('Strength weakness sentence could not advance the comparison', [
                'analysis_id' => $analysisId,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * このジョブが終わったことで、完了を待っていた診断全体を進める。
     */
    public function afterFinished(int $analysisId): void
    {
        $pipeline = app(AnalysisPipeline::class);
        $pipeline->updateAnalysisProgress($analysisId);
        $pipeline->maybeFinalizeAnalysis($analysisId);
    }
}
