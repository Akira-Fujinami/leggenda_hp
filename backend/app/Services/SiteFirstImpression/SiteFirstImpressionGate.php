<?php

namespace App\Services\SiteFirstImpression;

use App\Enums\AnalysisKind;
use App\Enums\JobType;
use App\Models\AnalysisJob;
use App\Models\WebsiteAnalysis;

/**
 * 依頼CX-5: 比較(サイト単位の完了)を、第一印象のジョブが終わるまで待たせるかどうかの判定(依頼CT・CQA-5と同じ考え方)。
 * AnalysisPipeline::maybeFinalizeWebsiteAnalysis()から呼ぶ。
 *
 * 待つのは次のすべてを満たすとき:
 *  - config('site_first_impression.enabled')がtrue(falseなら何も待たない ―― 既定)
 *  - 比較(AdminComparison)の自社サイト(is_primary)で、ブランド・ホイールを行う(skip_brand_wheelでない)
 *  - 結果のファイルがまだ無い(ジョブが失敗しても、失敗の記録が結果のファイルとして置かれるため、待ちは解ける)
 *  - ブランド・ホイールのジョブが終わってから、config('site_first_impression.wait_max_minutes')を過ぎていない
 *    (ジョブが永久に来ないときに、比較を完了できなくならないための保険)
 */
class SiteFirstImpressionGate
{
    public function __construct(private readonly SiteFirstImpressionStore $store) {}

    public function shouldWait(WebsiteAnalysis $websiteAnalysis): bool
    {
        if (! (bool) config('site_first_impression.enabled', false)) {
            return false;
        }

        $analysis = $websiteAnalysis->analysis;
        if ($analysis === null || $analysis->kind !== AnalysisKind::AdminComparison || $analysis->skip_brand_wheel === true) {
            return false;
        }

        if (! (bool) $websiteAnalysis->website?->is_primary) {
            return false;
        }

        if ($this->store->exists((int) $websiteAnalysis->analysis_id)) {
            return false;
        }

        $brandWheelJob = AnalysisJob::query()
            ->where('analysis_id', $websiteAnalysis->analysis_id)
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('job_type', JobType::GenerateBrandWheelAnalysis)
            ->first();

        if ($brandWheelJob === null || ! $brandWheelJob->status->isTerminal()) {
            return false;
        }

        $finishedAt = $brandWheelJob->completed_at ?? $brandWheelJob->failed_at ?? $brandWheelJob->updated_at;

        return $finishedAt === null || $finishedAt->gt(now()->subMinutes((int) config('site_first_impression.wait_max_minutes', 10)));
    }
}
