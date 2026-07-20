<?php

namespace App\Services\Scoring\Strategies;

use App\Models\MetricDefinition;
use App\Models\MetricResult;

/**
 * Lighthouseのカテゴリスコア(0-100)を採点する。
 */
class LighthouseMetricScorer implements MetricScoringStrategy
{
    use ExtractsNormalizedValue;

    public function calculateRatio(MetricDefinition $definition, MetricResult $result): float
    {
        $value = $this->numericValue($result);

        if ($value === null) {
            return 0.0;
        }

        return max(0.0, min(1.0, $value / 100));
    }
}
