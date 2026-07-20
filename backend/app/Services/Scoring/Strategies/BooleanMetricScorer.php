<?php

namespace App\Services\Scoring\Strategies;

use App\Models\MetricDefinition;
use App\Models\MetricResult;

class BooleanMetricScorer implements MetricScoringStrategy
{
    use ExtractsNormalizedValue;

    public function calculateRatio(MetricDefinition $definition, MetricResult $result): float
    {
        $value = $this->boolValue($result);

        if ($value === null) {
            $raw = $result->normalized_value['value'] ?? null;
            $value = (bool) $raw;
        }

        $ratio = $value ? 1.0 : 0.0;

        return $definition->higher_is_better ? $ratio : 1.0 - $ratio;
    }
}
