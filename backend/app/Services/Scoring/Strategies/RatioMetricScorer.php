<?php

namespace App\Services\Scoring\Strategies;

use App\Models\MetricDefinition;
use App\Models\MetricResult;

/**
 * 値自体が既に0.0〜1.0の達成率である指標向け(画像alt充足率等)。
 */
class RatioMetricScorer implements MetricScoringStrategy
{
    use ExtractsNormalizedValue;

    public function calculateRatio(MetricDefinition $definition, MetricResult $result): float
    {
        $value = $this->numericValue($result);

        if ($value === null) {
            return 0.0;
        }

        return max(0.0, min(1.0, $value));
    }
}
