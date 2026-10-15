<?php

namespace App\Services\SiteFirstImpression;

use App\Enums\AnalysisKind;
use App\Jobs\GenerateSiteFirstImpressionJob;
use App\Models\Analysis;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Support\Facades\Log;

/**
 * 依頼CX-5: 比較(AdminComparison)の自社サイト(is_primary)について、自社の巡回と依頼CRのコーポレートTOPの取得が
 * 終わったあとに、第一印象のジョブを1回だけ起動する(依頼CTのBranchSurveyItemsDispatcherと同じ場所から呼ぶ)。
 *
 * 呼び出し元は診断本体の進捗・完了判定には影響させない方針のため、ここで起きた例外は握って警告ログにするだけにする
 * ―― 起動に失敗しても、比較全体は失敗させない(失敗の記録を結果のファイルに置き、ページが無いだけにする)。
 * config('site_first_impression.enabled')がfalse(既定)のときは何もしない。結果のファイルが既にあれば起動しない
 * (同じ比較を再実行してもAIを呼び直さない)。PPTXのダウンロード時にはここを通らない(AIも外部通信も無い)。
 */
class SiteFirstImpressionDispatcher
{
    public function __construct(
        private readonly SiteFirstImpressionStore $store,
        private readonly SiteFirstImpressionService $service,
    ) {}

    public function dispatchFor(int $analysisId, ?int $websiteAnalysisId): void
    {
        if ($websiteAnalysisId === null || ! (bool) config('site_first_impression.enabled', false)) {
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

            if ($this->store->exists($analysisId)) {
                // 既にあるなら呼び直さない。ただし完了を待っていた比較は、ここで進める。
                $this->afterFinished($analysisId, $websiteAnalysisId);

                return;
            }

            GenerateSiteFirstImpressionJob::dispatch($websiteAnalysisId)->onQueue('ai');
        } catch (\Throwable $e) {
            Log::warning('Site first impression dispatch failed', [
                'analysis_id' => $analysisId,
                'website_analysis_id' => $websiteAnalysisId,
                'exception' => $e::class,
            ]);

            $this->recordFailureAndFinish($analysisId, $websiteAnalysisId, 'dispatch_failed');
        }
    }

    /**
     * ジョブの失敗(例外・タイムアウト)を、結果のファイルとして記録して、比較の完了を進める。
     * 失敗しても完了にはする ―― ページが無いだけになる。
     */
    public function recordFailureAndFinish(int $analysisId, int $websiteAnalysisId, string $reason): void
    {
        try {
            $websiteAnalysis = WebsiteAnalysis::query()->find($websiteAnalysisId);
            if ($websiteAnalysis !== null && ! $this->store->exists($analysisId)) {
                $this->service->recordFailure($websiteAnalysis, $reason);
            }
        } catch (\Throwable $e) {
            Log::error('Site first impression failure could not be recorded', [
                'analysis_id' => $analysisId,
                'website_analysis_id' => $websiteAnalysisId,
                'exception' => $e::class,
            ]);
        }

        try {
            $this->afterFinished($analysisId, $websiteAnalysisId);
        } catch (\Throwable $e) {
            Log::error('Site first impression could not advance the comparison', [
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
