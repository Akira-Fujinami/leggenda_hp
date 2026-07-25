<?php

namespace App\Jobs\Analysis\Concerns;

use App\Enums\AnalysisErrorCode;
use Illuminate\Support\Facades\Log;

/**
 * Analysisパイプラインのジョブが失敗した際、Renderのログストリーム(stderr)から
 * 原因を追跡できるよう、構造化した文脈情報を必ず記録するための共通処理。
 *
 * report($e)だけでは例外class/message/file/lineは残るが、どのAnalysis/
 * WebsiteAnalysis/JobType/試行回数での失敗かが分からず、本番ログの大量の
 * 行の中から該当ジョブの失敗を追跡するのが困難だった(2026-07-25の
 * 障害調査で判明)。ここではHTML本文・生API応答・Secret/Tokenの類は
 * 一切扱わない(例外messageは元々このアプリ自身が組み立てた安全な文言のみ)。
 */
trait LogsJobFailures
{
    private function logJobFailure(
        \Throwable $e,
        int $analysisId,
        ?int $websiteAnalysisId,
        string $jobType,
        int $attempt,
        float $elapsedSeconds,
        ?AnalysisErrorCode $errorCode = null,
    ): void {
        $context = [
            'analysis_id' => $analysisId,
            'website_analysis_id' => $websiteAnalysisId,
            'job_type' => $jobType,
            'attempt' => $attempt,
            'exception_class' => get_class($e),
            'exception_message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'error_code' => $errorCode?->value,
            'elapsed_seconds' => round($elapsedSeconds, 3),
        ];

        $previous = $e->getPrevious();
        if ($previous !== null) {
            $context['previous_exception_class'] = get_class($previous);
            $context['previous_exception_message'] = $previous->getMessage();
        }

        Log::error('Analysis job failed', $context);
    }
}
