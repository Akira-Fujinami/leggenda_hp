<?php

namespace App\Services\ComparisonCommentary;

use RuntimeException;

/**
 * 依頼CU: 強み・弱みの一文を書くAI呼び出しの失敗。errorCodeは結果のファイル・ログに残す(本文は残さない)。
 */
class ComparisonCommentaryException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
