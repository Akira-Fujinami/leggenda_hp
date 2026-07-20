<?php

namespace App\Services\Scoring\Strategies;

use App\Models\MetricDefinition;
use App\Models\MetricResult;

/**
 * 値が大きいほど良い指標向け。minimum_value(0点)からtarget_value(満点)まで
 * 線形補間する。target_value <= minimum_valueなど不正な設定では0点に
 * フォールバックする(例外を投げず安全側に倒す)。
 */
class LinearMetricScorer implements MetricScoringStrategy
{
    use ExtractsNormalizedValue;

    public function calculateRatio(MetricDefinition $definition, MetricResult $result): float
    {
        $value = $this->numericValue($result);
        $min = $definition->minimum_value !== null ? (float) $definition->minimum_value : null;
        $target = $definition->target_value !== null ? (float) $definition->target_value : null;

        if ($value === null || $min === null || $target === null || $target <= $min) {
            return 0.0;
        }

        return max(0.0, min(1.0, ($value - $min) / ($target - $min)));
    }
}
