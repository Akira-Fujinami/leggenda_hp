<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\AnalysisStatus;
use App\Enums\WebsiteAnalysisStatus;
use Illuminate\Support\Collection;

/**
 * 分析結果の完了判定ロジック。
 *
 * WebsiteAnalysis:
 *  - completed: 必須ジョブが成功し、表示可能な結果がある
 *  - partial:   一部のJobが失敗/unavailableだが、表示可能な結果がある
 *  - failed:    最低限のページ取得すらできず、表示可能な結果がない
 *
 * Analysis:
 *  - completed: 全サイトcompleted
 *  - partial:   completedまたはpartialが1件以上あり、failedもある(混在)
 *  - failed:    全サイトfailed
 */
class AnalysisStatusResolver
{
    /**
     * @param  Collection<int, \App\Models\AnalysisJob>  $jobs
     */
    public function resolveWebsiteAnalysisStatus(Collection $jobs, bool $hasUsableResult): WebsiteAnalysisStatus
    {
        if (! $hasUsableResult) {
            return WebsiteAnalysisStatus::Failed;
        }

        $hasFailure = $jobs->contains(fn ($job) => $job->status === AnalysisJobStatus::Failed);

        return $hasFailure ? WebsiteAnalysisStatus::Partial : WebsiteAnalysisStatus::Completed;
    }

    /**
     * @param  Collection<int, \App\Models\WebsiteAnalysis>  $websiteAnalyses
     */
    public function resolveAnalysisStatus(Collection $websiteAnalyses): AnalysisStatus
    {
        $allCompleted = $websiteAnalyses->every(fn ($wa) => $wa->status === WebsiteAnalysisStatus::Completed);
        if ($allCompleted) {
            return AnalysisStatus::Completed;
        }

        $allFailed = $websiteAnalyses->every(fn ($wa) => $wa->status === WebsiteAnalysisStatus::Failed);
        if ($allFailed) {
            return AnalysisStatus::Failed;
        }

        return AnalysisStatus::Partial;
    }
}
