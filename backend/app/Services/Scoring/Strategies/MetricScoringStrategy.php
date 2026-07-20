<?php

namespace App\Services\Scoring\Strategies;

use App\Models\MetricDefinition;
use App\Models\MetricResult;

/**
 * MetricDefinition.scoring_typeごとの採点方法。
 * 実測値(MetricResult.normalized_value)とMetricDefinitionの設定
 * (thresholds/min/target/max等)から、0.0〜1.0の達成率を返す。
 *
 * 実装は例外を投げてもよい ―― 呼び出し側(MetricScorer)が捕捉して
 * 安全に0点フォールバックする。
 */
interface MetricScoringStrategy
{
    public function calculateRatio(MetricDefinition $definition, MetricResult $result): float;
}
