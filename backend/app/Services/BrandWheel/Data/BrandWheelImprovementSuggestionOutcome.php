<?php

namespace App\Services\BrandWheel\Data;

/**
 * BrandWheelImprovementSuggestionProvider::analyze()の戻り値。
 * BrandWheelAnalysisOutcomeと同じ形(結果本体とトークン使用量を分けて持つ)。
 */
readonly class BrandWheelImprovementSuggestionOutcome
{
    public function __construct(
        public BrandWheelImprovementSuggestionResult $result,
        public ?int $usageInputTokens,
        public ?int $usageOutputTokens,
    ) {}
}
