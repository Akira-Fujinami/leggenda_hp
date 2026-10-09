<?php

namespace App\Services\TopMessageInsight;

use App\Enums\AnalysisKind;
use App\Jobs\GenerateTopMessageInsightJob;
use App\Models\Analysis;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Support\Facades\Log;

/**
 * 依頼CQ-3: 比較(AdminComparison)の流れの中で、各社のブランド・ホイールの判定が
 * 終わったあとに、会社ごとに1回だけ、トップメッセージ × 人事制度のジョブを起動する。
 *
 * 呼び出し元(GenerateBrandWheelAnalysisJob::cascadeProgress())は診断本体の進捗・完了判定
 * には影響させない方針のため、ここで起きた例外は握って警告ログにするだけにする
 * ―― このジョブの起動に失敗しても、比較全体は失敗させない(失敗の記録を結果のファイルに
 * 置き、比較の完了を待たせない。依頼CQ追補 CQA-5)。
 *
 * config('top_message_insight.enabled')がfalse(既定)のときは何もしない。
 * 確定した結果のファイルが既にあれば起動しない(同じ比較を再実行してもAIを呼び直さない)。
 */
class TopMessageInsightDispatcher
{
    public function __construct(
        private readonly TopMessageInsightStore $store,
        private readonly TopMessageInsightService $service,
    ) {}

    public function dispatchFor(int $analysisId, ?int $websiteAnalysisId): void
    {
        if ($websiteAnalysisId === null || ! (bool) config('top_message_insight.enabled', false)) {
            return;
        }

        try {
            $analysis = Analysis::query()->find($analysisId);

            if ($analysis === null || $analysis->kind !== AnalysisKind::AdminComparison) {
                return;
            }

            $existing = $this->store->read($analysisId, $websiteAnalysisId);
            if ($existing !== null && $this->service->isFinal($existing)) {
                // 既にあるなら呼び直さない。ただし完了を待っていた比較は、ここで進める。
                $this->afterFinished($analysisId, $websiteAnalysisId);

                return;
            }

            GenerateTopMessageInsightJob::dispatch($websiteAnalysisId)->onQueue('ai');
        } catch (\Throwable $e) {
            Log::warning('Top message insight dispatch failed', [
                'analysis_id' => $analysisId,
                'website_analysis_id' => $websiteAnalysisId,
                'exception' => $e::class,
            ]);

            $this->recordFailureAndFinish($analysisId, $websiteAnalysisId, 'ai_error', $e::class);
        }
    }

    /**
     * ジョブの失敗(例外・タイムアウト)を、結果のファイルとして記録して、比較の完了を進める。
     * 失敗しても完了にはする ―― そのページが無いだけ。
     */
    public function recordFailureAndFinish(int $analysisId, int $websiteAnalysisId, string $reason, ?string $errorCode): void
    {
        try {
            $websiteAnalysis = WebsiteAnalysis::query()->find($websiteAnalysisId);
            if ($websiteAnalysis !== null) {
                $this->service->recordFailure($websiteAnalysis, $reason, $errorCode);
            }
        } catch (\Throwable $e) {
            Log::error('Top message insight failure could not be recorded', [
                'analysis_id' => $analysisId,
                'website_analysis_id' => $websiteAnalysisId,
                'exception' => $e::class,
            ]);
        }

        try {
            $this->afterFinished($analysisId, $websiteAnalysisId);
        } catch (\Throwable $e) {
            Log::error('Top message insight could not advance the comparison', [
                'analysis_id' => $analysisId,
                'website_analysis_id' => $websiteAnalysisId,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * このジョブが終わったことで、完了を待っていた比較(サイト単位 → 診断全体)を進める。
     */
    public function afterFinished(int $analysisId, int $websiteAnalysisId): void
    {
        $pipeline = app(AnalysisPipeline::class);
        $pipeline->updateWebsiteAnalysisProgress($websiteAnalysisId);
        $pipeline->maybeFinalizeWebsiteAnalysis($websiteAnalysisId);
        $pipeline->updateAnalysisProgress($analysisId);
    }
}
