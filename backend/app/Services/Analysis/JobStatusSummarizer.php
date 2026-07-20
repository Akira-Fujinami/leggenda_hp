<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Models\AnalysisJob;
use Illuminate\Support\Collection;

/**
 * AnalysisJobの集合から件数集計(総数・完了・失敗・実行中・待機中・
 * スキップ・処理終了数)を算出する。Analysis全体・WebsiteAnalysisごとの
 * 両方の粒度で同じロジックを使う。
 *
 * finished(処理終了数) = completed + failed + skipped。
 * 失敗したJobも「処理としては終了している」ため finished に含める
 * (成否はstatus側で別途表現する ―― ProgressCalculatorと同じ方針)。
 */
class JobStatusSummarizer
{
    /**
     * @param  Collection<int, AnalysisJob>  $jobs
     * @return array{total: int, completed: int, failed: int, running: int, pending: int, skipped: int, finished: int}
     */
    public function summarize(Collection $jobs): array
    {
        $completed = $jobs->where('status', AnalysisJobStatus::Completed)->count();
        $failed = $jobs->where('status', AnalysisJobStatus::Failed)->count();
        $running = $jobs->where('status', AnalysisJobStatus::Running)->count();
        $pending = $jobs->where('status', AnalysisJobStatus::Pending)->count();

        // 現在のパイプラインは依存元Jobが失敗した場合も後続Jobを「スキップ」
        // せず、取得不能を自己診断してunavailable記録の上でcompleted扱いに
        // する設計のため(例: AnalyzeHtmlSeoJob::recordAllUnavailable())、
        // skippedは常に0になる。将来的にskipped状態を導入する場合に備えて
        // フィールドとしては用意しておく。
        $skipped = 0;

        return [
            'total' => $jobs->count(),
            'completed' => $completed,
            'failed' => $failed,
            'running' => $running,
            'pending' => $pending,
            'skipped' => $skipped,
            'finished' => $completed + $failed + $skipped,
        ];
    }
}
