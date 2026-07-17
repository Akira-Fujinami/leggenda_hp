<?php

namespace App\Jobs\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStatusResolver;

/**
 * サイト単位の全ジョブが終端状態になった後に、WebsiteAnalysisの最終ステータス
 * (completed/partial/failed)を確定する。
 *
 * スコア(score/max_available_score/coverage_rate等)はWebsiteAnalysisに
 * 非正規化して保存せず、結果取得時にMetricResultから都度計算する
 * (ScoreCalculator参照)。理由: MetricResultが唯一の正データであり、
 * 保存されたスコアが後から古くなる(MetricDefinitionの配点変更等)リスクを
 * 避けるため。計算コストはDB1クエリ+メモリ内計算のみで軽量。
 */
class FinalizeWebsiteAnalysisJob extends BaseWebsiteAnalysisJob
{
    public $tries = 1;

    public $timeout = 30;

    public function jobType(): JobType
    {
        return JobType::FinalizeWebsiteAnalysis;
    }

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        $jobs = AnalysisJobRecord::query()
            ->where('website_analysis_id', $this->websiteAnalysisId)
            ->whereIn('job_type', JobType::websiteLevelTypes())
            ->get();

        $fetchJob = $jobs->first(fn (AnalysisJobRecord $j) => $j->job_type === JobType::FetchStaticPage);
        $hasUsableResult = $fetchJob !== null && $fetchJob->status === AnalysisJobStatus::Completed;

        $status = app(AnalysisStatusResolver::class)->resolveWebsiteAnalysisStatus($jobs, $hasUsableResult);

        $websiteAnalysis->update([
            'status' => $status,
            'progress' => 100,
            'completed_at' => now(),
        ]);

        // このWebsiteAnalysisが、Analysis配下で最後に終端状態へ到達したサイトの
        // 場合、Analysis全体の集計(FinalizeAnalysisJob)をここで起動する。
        $pipeline->maybeFinalizeAnalysis($this->analysisId);
    }
}
