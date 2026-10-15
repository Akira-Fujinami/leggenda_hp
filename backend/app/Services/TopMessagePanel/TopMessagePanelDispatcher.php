<?php

namespace App\Services\TopMessagePanel;

use App\Enums\AnalysisKind;
use App\Jobs\GenerateTopMessagePanelJob;
use App\Models\Analysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Support\Facades\Log;

/**
 * 依頼CZ-5: 比較(AdminComparison)の全社の巡回が終わったあとに、トップメッセージと制度のパネルを作るジョブを1回だけ起動し、
 * そのジョブが終わるまで診断全体(Analysis)の完了を待たせる(依頼CV-5の強み・弱みの一文と同じ考え方)。
 * AnalysisPipeline::maybeFinalizeAnalysis()が、全社(WebsiteAnalysis)が終端状態になったあとにここへ聞く。
 *
 * 待つのは次のすべてを満たすとき:
 *  - config('top_message_panel.enabled')がtrue かつ config('top_message_draft.enabled')がtrue(falseなら何もしない ―― 既定)
 *  - 比較(AdminComparison)である
 *  - 結果のファイルがまだ無い(ジョブが失敗しても、失敗の記録が結果のファイルとして置かれるため、待ちは解ける)
 *  - 全社の巡回が終わってから、config('top_message_panel.wait_max_minutes')を過ぎていない
 *    (ジョブが永久に終わらないときに、比較を完了できなくならないための保険)
 *
 * 起動や記録で起きた例外は握って警告ログにするだけにする ―― 失敗しても比較全体は失敗させない
 * (依頼CSの素材ページになるだけ)。
 */
class TopMessagePanelDispatcher
{
    public function __construct(
        private readonly TopMessagePanelStore $store,
        private readonly TopMessagePanelService $service,
    ) {}

    /**
     * 診断全体の完了を待たせるか。待たせる場合は、ジョブを起動する(起動済みなら二重には起動されない)。
     */
    public function holdFinalization(Analysis $analysis): bool
    {
        if (! (bool) config('top_message_panel.enabled', false) || ! (bool) config('top_message_draft.enabled', false)) {
            return false;
        }

        if ($analysis->kind !== AnalysisKind::AdminComparison) {
            return false;
        }

        try {
            if ($this->store->exists((int) $analysis->id)) {
                return false;
            }

            $latest = $analysis->websiteAnalyses()->max('updated_at');
            if ($latest !== null && now()->parse($latest)->lt(now()->subMinutes((int) config('top_message_panel.wait_max_minutes', 15)))) {
                return false;
            }

            GenerateTopMessagePanelJob::dispatch((int) $analysis->id)->onQueue('ai');

            return true;
        } catch (\Throwable $e) {
            Log::warning('Top message panel dispatch failed', [
                'analysis_id' => $analysis->id,
                'exception' => $e::class,
            ]);

            $this->recordFailureAndFinish((int) $analysis->id, 'dispatch_failed');

            return false;
        }
    }

    /**
     * ジョブの失敗(例外・タイムアウト)を、結果のファイルとして記録して、比較の完了を進める。
     * 失敗しても完了にはする ―― 依頼CSの素材ページになるだけ。
     */
    public function recordFailureAndFinish(int $analysisId, string $reason): void
    {
        try {
            $analysis = Analysis::query()->find($analysisId);
            if ($analysis !== null && ! $this->store->exists($analysisId)) {
                $this->service->recordFailure($analysis, $reason);
            }
        } catch (\Throwable $e) {
            Log::error('Top message panel failure could not be recorded', [
                'analysis_id' => $analysisId,
                'exception' => $e::class,
            ]);
        }

        try {
            $this->afterFinished($analysisId);
        } catch (\Throwable $e) {
            Log::error('Top message panel could not advance the comparison', [
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
