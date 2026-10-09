<?php

namespace App\Services\CorporateTop;

use App\Enums\AnalysisKind;
use App\Jobs\FetchCorporateTopJob;
use App\Models\Analysis;
use App\Models\WebsiteAnalysis;
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
        if ($websiteAnalysisId === null || ! (bool) config('admin_comparison_pptx.corporate_top_enabled', true)) {
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
                return;
            }

            FetchCorporateTopJob::dispatch($websiteAnalysisId)->onQueue('analysis');
        } catch (\Throwable $e) {
            Log::warning('Corporate top dispatch failed', [
                'analysis_id' => $analysisId,
                'website_analysis_id' => $websiteAnalysisId,
                'exception' => $e::class,
            ]);
        }
    }
}
