<?php

namespace App\Services\BranchSurveyItems;

use App\Enums\AnalysisKind;
use App\Jobs\GenerateBranchSurveyItemsJob;
use App\Models\Analysis;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Support\Facades\Log;

/**
 * 依頼CT: 比較(AdminComparison)の自社サイト(is_primary)について、巡回とコーポレートTOPの取得
 * (依頼CRのFetchCorporateTopJob)が終わったあとに、枝ごとの判定のジョブを1回だけ起動する。
 *
 * 呼び出し元(CorporateTopDispatcher / FetchCorporateTopJob)は診断本体の進捗・完了判定には影響させない
 * 方針のため、ここで起きた例外は握って警告ログにするだけにする ―― 起動に失敗しても、比較全体は失敗させない
 * (失敗の記録を結果のファイルに置き、比較の完了を待たせない)。
 *
 * config('branch_survey_items.enabled')がfalse(既定)のときは何もしない。結果のファイルが既にあれば
 * 起動しない(同じ比較を再実行してもAIを呼び直さない)。
 */
class BranchSurveyItemsDispatcher
{
    public function __construct(
        private readonly BranchSurveyItemsStore $store,
        private readonly BranchSurveyItemsService $service,
    ) {}

    public function dispatchFor(int $analysisId, ?int $websiteAnalysisId): void
    {
        if ($websiteAnalysisId === null || ! (bool) config('branch_survey_items.enabled', false)) {
            return;
        }

        try {
            $analysis = Analysis::query()->find($analysisId);
            if ($analysis === null || $analysis->kind !== AnalysisKind::AdminComparison) {
                return;
            }

            $websiteAnalysis = WebsiteAnalysis::query()->with('website')->find($websiteAnalysisId);
            if ($websiteAnalysis === null || ! (bool) $websiteAnalysis->website?->is_primary) {
                return;
            }

            if ($this->store->exists($analysisId, $websiteAnalysisId)) {
                // 既にあるなら呼び直さない。ただし完了を待っていた比較は、ここで進める。
                $this->afterFinished($analysisId, $websiteAnalysisId);

                return;
            }

            GenerateBranchSurveyItemsJob::dispatch($websiteAnalysisId)->onQueue('ai');
        } catch (\Throwable $e) {
            Log::warning('Branch survey items dispatch failed', [
                'analysis_id' => $analysisId,
                'website_analysis_id' => $websiteAnalysisId,
                'exception' => $e::class,
            ]);

            $this->recordFailureAndFinish($analysisId, $websiteAnalysisId, 'dispatch_failed');
        }
    }

    /**
     * ジョブの失敗(例外・タイムアウト)を、結果のファイルとして記録して、比較の完了を進める。
     * 失敗しても完了にはする ―― 枝が「判定できませんでした」になるだけ。
     */
    public function recordFailureAndFinish(int $analysisId, int $websiteAnalysisId, string $reason): void
    {
        try {
            $websiteAnalysis = WebsiteAnalysis::query()->find($websiteAnalysisId);
            if ($websiteAnalysis !== null && ! $this->store->exists($analysisId, $websiteAnalysisId)) {
                $this->service->recordFailure($websiteAnalysis, $reason);
            }
        } catch (\Throwable $e) {
            Log::error('Branch survey items failure could not be recorded', [
                'analysis_id' => $analysisId,
                'website_analysis_id' => $websiteAnalysisId,
                'exception' => $e::class,
            ]);
        }

        try {
            $this->afterFinished($analysisId, $websiteAnalysisId);
        } catch (\Throwable $e) {
            Log::error('Branch survey items could not advance the comparison', [
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
