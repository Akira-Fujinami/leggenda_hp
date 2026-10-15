<?php

namespace App\Jobs;

use App\Models\WebsiteAnalysis;
use App\Services\BranchSurveyItems\BranchSurveyItemsDispatcher;
use App\Services\BranchSurveyItems\BranchSurveyItemsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 依頼CT: 自社(WebsiteAnalysis)の階層図について、TOPページと枝ごとに、調査14項目のどれが書かれているかを
 * 判定する(1つのジョブの中で、枝ごとにAIを呼ぶ)。巡回とコーポレートTOPの取得が終わったあと
 * (BranchSurveyItemsDispatcher、CorporateTopDispatcher / FetchCorporateTopJobから)に起動する。
 *
 * このジョブが失敗しても、比較全体は失敗させない ―― AnalysisJobの行を作らず(進捗・完了判定に関わらない)、
 * 例外はここで受けてログに残し、失敗の記録を結果のファイルに置く(枝は「判定できませんでした」になる)。
 * 再実行しても結果のファイルがあればAIは呼ばない。AI呼び出しの再試行はジョブ側では行わない
 * (呼び出し回数を増やさないため)。
 */
class GenerateBranchSurveyItemsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout;

    public $uniqueFor;

    public function __construct(public readonly int $websiteAnalysisId)
    {
        // 対象は最大 TOP + 枝(既定6)。1回ごとのタイムアウト+余裕。
        $this->timeout = ((int) config('branch_survey_items.timeout', 90)) * ((int) config('branch_survey_items.max_branches', 6) + 1) + 30;
        $this->uniqueFor = $this->timeout * 3;
    }

    public function uniqueId(): string
    {
        return "branch-survey-items:{$this->websiteAnalysisId}";
    }

    public function handle(BranchSurveyItemsService $service, BranchSurveyItemsDispatcher $dispatcher): void
    {
        $websiteAnalysis = WebsiteAnalysis::find($this->websiteAnalysisId);

        if ($websiteAnalysis === null) {
            return;
        }

        $failure = null;

        try {
            $service->generate($websiteAnalysis);
        } catch (\Throwable $e) {
            Log::error('Branch survey items failed', [
                'analysis_id' => $websiteAnalysis->analysis_id,
                'website_analysis_id' => $websiteAnalysis->id,
                'exception' => $e::class,
            ]);
            $failure = $e::class;
        }

        // 成功・失敗のどちらでも、完了を待っていた比較を進める(失敗しても完了にはする)。
        if ($failure !== null) {
            $dispatcher->recordFailureAndFinish((int) $websiteAnalysis->analysis_id, (int) $websiteAnalysis->id, $failure);
        } else {
            $dispatcher->afterFinished((int) $websiteAnalysis->analysis_id, (int) $websiteAnalysis->id);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Branch survey items job failed', [
            'website_analysis_id' => $this->websiteAnalysisId,
            'exception' => $exception !== null ? $exception::class : null,
        ]);

        // タイムアウト等でhandle()の外で終わらされた場合も、比較の完了を待たせない。
        $websiteAnalysis = WebsiteAnalysis::find($this->websiteAnalysisId);
        if ($websiteAnalysis !== null) {
            app(BranchSurveyItemsDispatcher::class)->recordFailureAndFinish((int) $websiteAnalysis->analysis_id, (int) $websiteAnalysis->id, $exception !== null ? $exception::class : 'job_failed');
        }
    }
}
