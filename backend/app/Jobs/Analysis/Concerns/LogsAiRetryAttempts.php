<?php

namespace App\Jobs\Analysis\Concerns;

use App\Services\BrandWheel\BrandWheelAnalysisException;
use Illuminate\Support\Facades\Log;

/**
 * 依頼U-3(2026-08-26): 改善提案・判定のOpenAI呼び出しがレート制限
 * (429)等でrelease()により再試行された回数を観測できるようにする
 * (5件同時実行時に実際どれだけレート制限に当たっているかを測るため、
 * 依頼者指定)。GenerateBrandWheelAnalysisJob/GenerateBrandWheelImprovement
 * SuggestionJobの両方が同じ形式でログを出す(同じ方針を適用する、
 * 依頼者指定)。
 *
 * 本文・プロンプト・APIキーの類は一切含めない ―― $e->getMessage()は
 * このアプリ自身が組み立てた定型文(例:「OpenAI APIのレート制限に
 * 達しました。」)のみで、AIの生応答やAPIキーを含まないことを確認済みだが、
 * 万一の混入経路を残さないため、ここでも意図的にerror_codeのみを記録し、
 * メッセージ本文はログに含めない。
 */
trait LogsAiRetryAttempts
{
    /**
     * Laravel自身のIlluminate\Queue\Worker::calculateBackoff()と同じ規則
     * (attempts()-1番目の値、配列の範囲を超えたら末尾の値を繰り返す)で、
     * 現在の試行回数に応じたバックオフ秒数を選ぶ。このジョブは例外を
     * 送出せず自前でrelease()するため、Laravelのこの自動計算は使われず
     * (Illuminate\Queue\CallQueuedHandler経由の自動リトライ専用)、
     * 同じ規則をここで再現する。
     *
     * @param  list<int>  $backoff
     */
    private function resolveBackoffSeconds(array $backoff): int
    {
        $backoff = $backoff !== [] ? array_values($backoff) : [30];
        $index = $this->attempts() - 1;

        return (int) ($backoff[$index] ?? $backoff[array_key_last($backoff)]);
    }

    /**
     * リトライを行う直前(release()の直前)に1回呼ぶ。実際に待つ秒数が
     * Retry-Afterヘッダ由来かbackoff由来かを区別できるようにする。
     */
    private function logAiRetryScheduled(
        int $analysisId,
        ?int $websiteAnalysisId,
        BrandWheelAnalysisException $e,
        int $waitSeconds,
        bool $waitFromRetryAfterHeader,
    ): void {
        Log::info('Brand wheel AI call hit a retryable failure; scheduling a retry', [
            'analysis_id' => $analysisId,
            'website_analysis_id' => $websiteAnalysisId,
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
            'error_code' => $e->errorCode,
            'wait_seconds' => $waitSeconds,
            'wait_source' => $waitFromRetryAfterHeader ? 'retry_after_header' : 'backoff',
        ]);
    }

    /**
     * $triesを使い切って(またはretryUntil()の期限切れで)最終的にerror
     * 確定する直前に1回呼ぶ。「1回で諦めたのか粘ったのか」が既存のログ
     * だけでは区別できなかったため(依頼者指摘)、最終試行回数を明示する。
     */
    private function logAiRetriesExhausted(
        int $analysisId,
        ?int $websiteAnalysisId,
        BrandWheelAnalysisException $e,
    ): void {
        Log::warning('Brand wheel AI call failed after exhausting all retries', [
            'analysis_id' => $analysisId,
            'website_analysis_id' => $websiteAnalysisId,
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
            'error_code' => $e->errorCode,
        ]);
    }
}
