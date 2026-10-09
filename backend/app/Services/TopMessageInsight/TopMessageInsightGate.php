<?php

namespace App\Services\TopMessageInsight;

use App\Enums\AnalysisKind;
use App\Enums\JobType;
use App\Models\AnalysisJob;
use App\Models\WebsiteAnalysis;

/**
 * 依頼CQ追補 CQA-5: 比較(サイト単位の完了)を、トップメッセージ × 人事制度のジョブが終わるまで
 * 待たせるかどうかの判定。AnalysisPipeline::maybeFinalizeWebsiteAnalysis()から呼ぶ。
 *
 * 待つのは次のすべてを満たすとき:
 *  - config('top_message_insight.enabled')がtrue(falseなら何も待たない ―― 既定)
 *  - 比較(AdminComparison)で、ブランド・ホイールを行う(skip_brand_wheelでない)
 *  - この会社の結果のファイルがまだ無い(ジョブが失敗しても、失敗の記録が結果のファイルとして
 *    置かれる(TopMessageInsightService::recordFailure())ため、待ちは解ける)
 *  - ブランド・ホイールのジョブが終わってから、config('top_message_insight.wait_max_minutes')を
 *    過ぎていない(ジョブが永久に来ないときに、比較を完了できなくならないための保険)
 *
 * ブランド・ホイールのジョブ自体がまだ終わっていないときは、そのジョブが完了判定を止めているため、
 * ここでは待たない(終わった時点でジョブを起動する)。
 */
class TopMessageInsightGate
{
    public function __construct(private readonly TopMessageInsightStore $store) {}

    public function shouldWait(WebsiteAnalysis $websiteAnalysis): bool
    {
        if (! (bool) config('top_message_insight.enabled', false)) {
            return false;
        }

        $analysis = $websiteAnalysis->analysis;
        if ($analysis === null || $analysis->kind !== AnalysisKind::AdminComparison || $analysis->skip_brand_wheel === true) {
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

        return $finishedAt === null || $finishedAt->gt(now()->subMinutes((int) config('top_message_insight.wait_max_minutes', 10)));
    }
}
