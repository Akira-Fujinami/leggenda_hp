<?php

namespace App\Jobs;

use App\Models\WebsiteAnalysis;
use App\Services\TopMessageInsight\TopMessageInsightDispatcher;
use App\Services\TopMessageInsight\TopMessageInsightException;
use App\Services\TopMessageInsight\TopMessageInsightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 依頼CQ-3: 会社(WebsiteAnalysis)ごとに1回、トップメッセージ × 人事制度の結果を作る。
 * 各社のブランド・ホイールの判定が終わったあと
 * (TopMessageInsightDispatcher、GenerateBrandWheelAnalysisJob::cascadeProgress()から)に起動する。
 *
 * このジョブが失敗しても、比較全体は失敗させない ―― AnalysisJobの行を作らず(進捗・
 * 完了判定に関わらない)、例外はここで受けてログに残すだけにする。そのページが
 * 作られないだけ。再実行しても結果のファイルがあればAIは呼ばない
 * (TopMessageInsightService::generate())。AI呼び出しの再試行はジョブ側では行わない
 * (呼び出し回数を増やさないため)。
 */
class GenerateTopMessageInsightJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout;

    public $uniqueFor;

    public function __construct(public readonly int $websiteAnalysisId)
    {
        $this->timeout = ((int) config('top_message_insight.timeout', 120)) + 30;
        $this->uniqueFor = $this->timeout * 3;
    }

    public function uniqueId(): string
    {
        return "top-message-insight:{$this->websiteAnalysisId}";
    }

    public function handle(TopMessageInsightService $service, TopMessageInsightDispatcher $dispatcher): void
    {
        $websiteAnalysis = WebsiteAnalysis::find($this->websiteAnalysisId);

        if ($websiteAnalysis === null) {
            return;
        }

        $failure = null;

        try {
            $service->generate($websiteAnalysis);
        } catch (TopMessageInsightException $e) {
            Log::warning('Top message insight skipped: AI call or setup failed', [
                'analysis_id' => $websiteAnalysis->analysis_id,
                'website_analysis_id' => $websiteAnalysis->id,
                'error_code' => $e->errorCode,
            ]);
            $failure = [in_array($e->errorCode, ['PROVIDER_NOT_OPENAI', 'OPENAI_NOT_CONFIGURED'], true) ? 'provider_unavailable' : 'ai_error', $e->errorCode];
        } catch (\Throwable $e) {
            Log::error('Top message insight failed', [
                'analysis_id' => $websiteAnalysis->analysis_id,
                'website_analysis_id' => $websiteAnalysis->id,
                'exception' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
            $failure = ['ai_error', $e::class];
        }

        // 成功・失敗のどちらでも、完了を待っていた比較を進める(失敗しても完了にはする。そのページが無いだけ)。
        if ($failure !== null) {
            $dispatcher->recordFailureAndFinish((int) $websiteAnalysis->analysis_id, (int) $websiteAnalysis->id, $failure[0], $failure[1]);
        } else {
            $dispatcher->afterFinished((int) $websiteAnalysis->analysis_id, (int) $websiteAnalysis->id);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Top message insight job failed', [
            'website_analysis_id' => $this->websiteAnalysisId,
            'exception' => $exception !== null ? $exception::class : null,
        ]);

        // タイムアウト等でhandle()の外で終わらされた場合も、比較の完了を待たせない。
        $websiteAnalysis = WebsiteAnalysis::find($this->websiteAnalysisId);
        if ($websiteAnalysis !== null) {
            app(TopMessageInsightDispatcher::class)->recordFailureAndFinish((int) $websiteAnalysis->analysis_id, (int) $websiteAnalysis->id, 'ai_error', $exception !== null ? $exception::class : null);
        }
    }
}
