<?php

namespace App\Services\Semrush;

/**
 * SeoDataProvider実装(Semrush等)固有のレスポンス形式をLaravel側の
 * Job/Controller/ScoreCalculatorへ一切漏らさないための例外。
 * errorCodeはAnalysisErrorCodeの値と同じ命名規則の文字列を使う
 * (ANALYZER_AUTH_FAILED等と同様の粒度)。
 */
class SeoProviderException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $isRetryable = false,
        public readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
