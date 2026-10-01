<?php

namespace App\Support\Admin;

use App\Models\Analysis;

/**
 * 依頼CJ-2(2026-10-01): AdminComparisonService::createStandalone()の戻り値。
 * 作成直後の画面で「既存企業に一致したか、新規作成したか」を明確に示す
 * 必要がある(依頼者指定 ―― 誤った一致に気づけるようにするため)ため、
 * Analysisだけでなくこの真偽値もあわせて返す。
 */
readonly class StandaloneComparisonResult
{
    public function __construct(
        public Analysis $analysis,
        public bool $matchedExistingCompany,
    ) {}
}
