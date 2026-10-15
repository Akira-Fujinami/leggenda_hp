<?php

namespace App\Jobs;

use App\Models\WebsiteAnalysis;
use App\Services\SiteFirstImpression\SiteFirstImpressionDispatcher;
use App\Services\SiteFirstImpression\SiteFirstImpressionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 依頼CX-5: 自社(WebsiteAnalysis)のコーポレートTOP・採用TOPの第一印象を、画面の画像(analyzer)とAIで作る
 * (AIを呼ぶのは1回)。自社の巡回と依頼CRのコーポレートTOPの取得が終わったあと(SiteFirstImpressionDispatcher)に起動する。
 *
 * このジョブが失敗しても、比較全体は失敗させない ―― AnalysisJobの行を作らず(進捗・完了判定に関わらない)、
 * 例外はここで受けてログに残し、失敗の記録を結果のファイルに置く(ページが無いだけ)。
 * 再実行しても結果のファイルがあればAIは呼ばない。AI呼び出しの再試行はジョブ側では行わない(呼び出し回数を増やさないため)。
 */
class GenerateSiteFirstImpressionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout;

    public $uniqueFor;

    public function __construct(public readonly int $websiteAnalysisId)
    {
        // 画面の撮影(2ページ、analyzer側の上限90秒ずつ)+AI呼び出し+余裕。
        $this->timeout = 90 * 2 + ((int) config('site_first_impression.timeout', 120)) * 2 + 30;
        $this->uniqueFor = $this->timeout * 3;
    }

    public function uniqueId(): string
    {
        return "site-first-impression:{$this->websiteAnalysisId}";
    }

    public function handle(SiteFirstImpressionService $service, SiteFirstImpressionDispatcher $dispatcher): void
    {
        $websiteAnalysis = WebsiteAnalysis::find($this->websiteAnalysisId);

        if ($websiteAnalysis === null) {
            return;
        }

        $failure = null;

        try {
            $service->generate($websiteAnalysis);
        } catch (\Throwable $e) {
            Log::error('Site first impression failed', [
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
        Log::error('Site first impression job failed', [
            'website_analysis_id' => $this->websiteAnalysisId,
            'exception' => $exception !== null ? $exception::class : null,
        ]);

        // タイムアウト等でhandle()の外で終わらされた場合も、比較の完了を待たせない。
        $websiteAnalysis = WebsiteAnalysis::find($this->websiteAnalysisId);
        if ($websiteAnalysis !== null) {
            app(SiteFirstImpressionDispatcher::class)->recordFailureAndFinish((int) $websiteAnalysis->analysis_id, (int) $websiteAnalysis->id, $exception !== null ? $exception::class : 'job_failed');
        }
    }
}
