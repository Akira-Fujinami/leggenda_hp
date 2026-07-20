<?php

namespace App\Services\Scoring\Strategies;

use App\Models\MetricDefinition;
use App\Models\MetricResult;

/**
 * 将来の人手/AI評価向け。normalized_value.valueに既に0.0〜1.0の
 * 達成率が入っている前提で、そのまま採用する。
 */
class ManualMetricScorer implements MetricScoringStrategy
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
