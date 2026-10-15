<?php

namespace App\Services\BranchSurveyItems;

use App\Enums\AnalysisKind;
use App\Enums\JobType;
use App\Models\AnalysisJob;
use App\Models\WebsiteAnalysis;

/**
 * 依頼CT: 比較(サイト単位の完了)を、枝ごとの判定のジョブが終わるまで待たせるかどうかの判定
 * (依頼CQ追補 CQA-5と同じ考え方)。AnalysisPipeline::maybeFinalizeWebsiteAnalysis()から呼ぶ。
 *
 * 待つのは次のすべてを満たすとき:
 *  - config('branch_survey_items.enabled')がtrue(falseなら何も待たない ―― 既定)
 *  - 比較(AdminComparison)の自社サイト(is_primary)で、ブランド・ホイールを行う(skip_brand_wheelでない)
 *  - 結果のファイルがまだ無い(ジョブが失敗しても、失敗の記録が結果のファイルとして置かれるため、待ちは解ける)
 *  - ブランド・ホイールのジョブが終わってから、config('branch_survey_items.wait_max_minutes')を
 *    過ぎていない(ジョブが永久に来ないときに、比較を完了できなくならないための保険)
 */
class BranchSurveyItemsGate
{
    public function __construct(private readonly BranchSurveyItemsStore $store) {}

    public function shouldWait(WebsiteAnalysis $websiteAnalysis): bool
    {
        if (! (bool) config('branch_survey_items.enabled', false)) {
            return false;
        }

        $analysis = $websiteAnalysis->analysis;
        if ($analysis === null || $analysis->kind !== AnalysisKind::AdminComparison || $analysis->skip_brand_wheel === true) {
            return false;
        }

        if (! (bool) $websiteAnalysis->website?->is_primary) {
            return false;
        }

        if ($this->store->exists((int) $websiteAnalysis->analysis_id, (int) $websiteAnalysis->id)) {
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

        return $finishedAt === null || $finishedAt->gt(now()->subMinutes((int) config('branch_survey_items.wait_max_minutes', 10)));
    }
}
