<?php

namespace App\Services\BrandWheel;

use App\Services\BrandWheel\Data\BrandWheelImprovementSuggestionInput;
use App\Services\BrandWheel\Data\BrandWheelImprovementSuggestionOutcome;

/**
 * 改善提案(page6)AI ProviderのInterface。BrandWheelAnalysisProviderと同じ
 * 方針(mock/openaiの2実装、Factoryで解決)。
 */
interface BrandWheelImprovementSuggestionProvider
{
    public function name(): string;

    public function model(): ?string;

    public function promptVersion(): ?string;

    public function analyze(BrandWheelImprovementSuggestionInput $input): BrandWheelImprovementSuggestionOutcome;
}
