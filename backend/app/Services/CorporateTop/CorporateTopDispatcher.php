<?php

namespace App\Services\CorporateTop;

use App\Enums\AnalysisKind;
use App\Jobs\FetchCorporateTopJob;
use App\Models\Analysis;
use App\Models\WebsiteAnalysis;
use App\Services\BranchSurveyItems\BranchSurveyItemsDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * 依頼CR-1: 比較(AdminComparison)の自社サイト(is_primary)について、コーポレートTOPの取得ジョブを
 * 1回だけ起動する。呼び出し元(GenerateBrandWheelAnalysisJob::cascadeProgress())は診断本体の進捗・
 * 完了判定に影響させない方針のため、例外は握って警告ログにするだけ。取得済みなら起動しない。
 * PPTXのダウンロード時にはここを通らない(ダウンロードで外部へ通信しない)。
 */
class CorporateTopDispatcher
{
    public function __construct(private readonly CorporateTopStore $store) {}

    public function dispatchFor(int $analysisId, ?int $websiteAnalysisId): void
    {
        if ($websiteAnalysisId === null) {
            return;
        }

        // 依頼CT: コーポレートTOPを取得しない設定のときは、取得を待たずに枝ごとの判定へ進む。
        if (! (bool) config('admin_comparison_pptx.corporate_top_enabled', true)) {
            app(BranchSurveyItemsDispatcher::class)->dispatchFor($analysisId, $websiteAnalysisId);

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
                // 取得済み: 取得ジョブは出さないので、ここから枝ごとの判定へ進む(依頼CT)。
                app(BranchSurveyItemsDispatcher::class)->dispatchFor($analysisId, $websiteAnalysisId);

                return;
            }

            // 取得ジョブが終わったあと(成功・失敗のどちらでも)、枝ごとの判定へ進む(FetchCorporateTopJob)。
            FetchCorporateTopJob::dispatch($websiteAnalysisId)->onQueue('analysis');
        } catch (\Throwable $e) {
            Log::warning('Corporate top dispatch failed', [
                'analysis_id' => $analysisId,
                'website_analysis_id' => $websiteAnalysisId,
                'exception' => $e::class,
            ]);

            // 取得ジョブを出せなかったときも、枝ごとの判定は進める(階層図はいまの描き方になるだけ)。
            app(BranchSurveyItemsDispatcher::class)->dispatchFor($analysisId, $websiteAnalysisId);
        }
    }
}
