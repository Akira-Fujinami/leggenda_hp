<?php

namespace App\Services\Scoring\Strategies;

use App\Models\MetricDefinition;
use App\Models\MetricResult;

/**
 * minimum_value〜maximum_valueの範囲内であれば満点、範囲外であれば0点
 * (例: titleの推奨文字数レンジ)。
 */
class RangeMetricScorer implements MetricScoringStrategy
{
    use ExtractsNormalizedValue;

    public function calculateRatio(MetricDefinition $definition, MetricResult $result): float
    {
        $value = $this->numericValue($result);
        $min = $definition->minimum_value !== null ? (float) $definition->minimum_value : null;
        $max = $definition->maximum_value !== null ? (float) $definition->maximum_value : null;

        if ($value === null || $min === null || $max === null || $max < $min) {
            return 0.0;
        }

        return ($value >= $min && $value <= $max) ? 1.0 : 0.0;
    }
}
