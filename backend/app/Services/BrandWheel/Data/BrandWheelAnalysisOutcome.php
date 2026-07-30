<?php

namespace App\Services\BrandWheel\Data;

/**
 * BrandWheelAnalysisProvider::analyze()の戻り値。結果本体とトークン使用量を
 * 分けて持つ(AiAnalysisOutcomeと同じ形)。
 */
readonly class BrandWheelAnalysisOutcome
{
    public function __construct(
        public BrandWheelAnalysisResult $result,
        public ?int $usageInputTokens,
        public ?int $usageOutputTokens,
    ) {}
}
