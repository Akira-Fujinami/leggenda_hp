<?php

namespace App\Services\Scoring\Strategies;

use App\Models\MetricDefinition;
use App\Models\MetricResult;

/**
 * 値が小さいほど良い指標向け(LCP・TBT等)。target_value(理想値・満点)から
 * maximum_value(許容上限・0点)まで線形に減点する。
 */
class InverseLinearMetricScorer implements MetricScoringStrategy
{
    use ExtractsNormalizedValue;

    public function calculateRatio(MetricDefinition $definition, MetricResult $result): float
    {
        $value = $this->numericValue($result);
        $target = $definition->target_value !== null ? (float) $definition->target_value : null;
        $max = $definition->maximum_value !== null ? (float) $definition->maximum_value : null;

        if ($value === null || $target === null || $max === null || $max <= $target) {
            return 0.0;
        }

        return max(0.0, min(1.0, ($max - $value) / ($max - $target)));
    }
}
