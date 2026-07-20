<?php

namespace App\Services\AiAnalysis;

/**
 * AI_PROVIDER設定の解決失敗や、AI Provider実装内部のエラーを表す例外。
 * SeoProviderExceptionと同じ命名規則のerrorCode・retry方針を使う。
 */
class AiAnalysisException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
        public readonly bool $isRetryable = false,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
