<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use Illuminate\Support\Collection;

/**
 * AnalysisJobの状態から進捗(0-100)をサーバー側で算出する。
 * 失敗したJobも「完了扱い」として進捗には加算する
 * (結果の成否はWebsiteAnalysis/Analysisのstatusで別途表現する)。
 */
class ProgressCalculator
{
    /**
     * @param  Collection<int, \App\Models\AnalysisJob>  $jobs  1つのWebsiteAnalysisに紐づくJob群
     */
    public function forWebsiteAnalysis(Collection $jobs): int
    {
        $total = 0;

        foreach (JobType::websiteLevelTypes() as $type) {
            $job = $jobs->first(fn ($j) => $j->job_type === $type);

            if ($job !== null && in_array($job->status, [AnalysisJobStatus::Completed, AnalysisJobStatus::Failed], true)) {
                $total += $type->weight();
            }
        }

        return min(100, $total);
    }

    /**
     * @param  Collection<int, \App\Models\WebsiteAnalysis>  $websiteAnalyses
     */
    public function forAnalysis(Collection $websiteAnalyses): int
    {
        if ($websiteAnalyses->isEmpty()) {
            return 0;
        }

        return (int) round($websiteAnalyses->avg('progress'));
    }
}
