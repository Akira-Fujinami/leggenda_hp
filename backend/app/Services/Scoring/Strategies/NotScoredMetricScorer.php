<?php

namespace App\Services\Scoring\Strategies;

use App\Models\MetricDefinition;
use App\Models\MetricResult;

/**
 * 採点対象外の指標(例: 検出された技術の種類そのもの)。
 * 実際の「分母から除外する」判定はMetricScorer側で
 * scoring_type=not_scoredを見て行うため、このStrategyが呼ばれることは
 * 通常ないが、防御的に常に0を返す。
 */
class NotScoredMetricScorer implements MetricScoringStrategy
{
    public function calculateRatio(MetricDefinition $definition, MetricResult $result): float
    {
        return 0.0;
    }
}
