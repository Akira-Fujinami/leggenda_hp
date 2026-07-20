<?php

namespace App\Services\Scoring\Strategies;

use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Support\Scoring\ScoreThresholdSet;

/**
 * MetricDefinition.thresholds (jsonb) の区間ごとのscore_rateを適用する。
 * 不正なthresholds設定はScoreThresholdSet::fromArray()がnullを返すため、
 * その場合は0点にフォールバックする(500エラーにはしない)。
 */
class ThresholdMetricScorer implements MetricScoringStrategy
{
    use ExtractsNormalizedValue;

    public function calculateRatio(MetricDefinition $definition, MetricResult $result): float
    {
        $value = $this->numericValue($result);

        if ($value === null) {
            return 0.0;
        }

        $thresholds = ScoreThresholdSet::fromArray($definition->thresholds);

        if ($thresholds === null) {
            return 0.0;
        }

        return $thresholds->rateFor($value);
    }
}
