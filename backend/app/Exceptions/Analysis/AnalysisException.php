<?php

namespace App\Exceptions\Analysis;

use App\Enums\AnalysisErrorCode;

/**
 * 分析パイプライン内で発生する「想定内の失敗」を表す例外。
 * Jobはこれを捕捉してAnalysisJob/WebsiteAnalysisの状態を更新し、
 * チェーン全体を止めずに処理を継続する。
 */
class AnalysisException extends \RuntimeException
{
    public function __construct(
        public readonly AnalysisErrorCode $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
