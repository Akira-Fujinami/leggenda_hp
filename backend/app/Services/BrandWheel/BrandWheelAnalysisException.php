<?php

namespace App\Services\BrandWheel;

/**
 * BRAND_WHEEL_AI_PROVIDER設定の解決失敗や、Provider実装内部のエラーを表す例外。
 * AiAnalysisExceptionと同じ命名規則・retry方針を使う。
 */
class BrandWheelAnalysisException extends \RuntimeException
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
