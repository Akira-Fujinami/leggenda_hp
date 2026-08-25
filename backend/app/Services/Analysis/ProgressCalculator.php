<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use Illuminate\Support\Collection;

/**
 * AnalysisJobの状態から進捗(0-100)をサーバー側で算出する。
 * 失敗したJobも「完了扱い」として進捗には加算する
 * (結果の成否はWebsiteAnalysis/Analysisのstatusで別途表現する)。
 *
 * 依頼M-1(2026-08-25): 従来は「完了/失敗のいずれかで満額、それ以外は0点」の
 * 二値だった。CrawlWebsite/RenderCrawledPagesは巡回・レンダリングの進行に
 * 応じてAnalysisJob.progress(0-99、実行中の間だけ更新される。100は
 * markCompleted()の専権)を更新するため、status=Runningの間はその
 * progressに応じた部分点を与える。既存の全JobType(FetchStaticPage等)は
 * markRunning()がprogressに一切触れず既定値0のまま実行中を過ごすため、
 * この部分点ロジックを追加してもRunning中は0点のまま ―― 完了時に満額が
 * 一括で入る従来の挙動と完全に同一である(この関数の変更前後でcrawl_site=
 * falseの進捗推移が変わらないことの根拠)。
 *
 * 依頼N(2026-08-25): 依頼M-1まではJobType::weight()の絶対値をそのまま
 * 合計しているだけで、対象外(skip_lighthouse等でexcludeSkippedJobTypes()
 * により除外され、AnalysisJob行自体が作られない = $job===null)の
 * ジョブ種別があっても、その重みは分子にも分母にも一切現れなかった。
 * その結果、除外された種別の重みの合計ぶんだけ、到達できる進捗の上限が
 * 100%未満に恒久的に固定される不具合があった(例: リード診断は
 * RunLighthouse/CaptureScreenshot*を省略するため、巡回なしの場合
 * 到達上限が100点満点中73点までしか上がらず、最後にFinalizeWebsiteAnalysisJob
 * がstatus確定と同時にDBのprogressカラムへ強制的に100を書き込むことで
 * 表面化していなかっただけだった)。
 *
 * この依頼で「行が存在する($job!==null)ジョブ種別の重みの合計」を分母
 * ($possible)とし、その中でどれだけ稼いだか($earned)を分子とする正規化
 * 方式に変更した。$possibleはregisterWebsiteJobPlaceholders()が
 * WebsiteAnalysis開始時に一括登録した(=除外されなかった)ジョブ種別の
 * 集合で決まり、実行中に変化しないため、$earnedが単調非減少である限り
 * 進捗が途中で減ることはない。
 */
class ProgressCalculator
{
    /**
     * @param  Collection<int, \App\Models\AnalysisJob>  $jobs  1つのWebsiteAnalysisに紐づくJob群
     */
    public function forWebsiteAnalysis(Collection $jobs): int
    {
        $earned = 0.0;
        $possible = 0;

        foreach (JobType::websiteLevelTypes() as $type) {
            $job = $jobs->first(fn ($j) => $j->job_type === $type);

            if ($job === null) {
                continue;
            }

            $possible += $type->weight();

            if (in_array($job->status, [AnalysisJobStatus::Completed, AnalysisJobStatus::Failed], true)) {
                $earned += $type->weight();
            } elseif ($job->status === AnalysisJobStatus::Running) {
                $earned += $type->weight() * (max(0, min(100, $job->progress)) / 100);
            }
        }

        if ($possible === 0) {
            return 0;
        }

        return min(100, (int) round(100 * $earned / $possible));
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
