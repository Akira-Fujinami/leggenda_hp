<?php

namespace App\Services\Recommendation;

use App\Enums\RecommendationEffort;
use App\Enums\RecommendationImpact;

/**
 * 改善提案のsort_score(優先順位)を算出する。UIには一切ロジックを置かず、
 * ここに一元化する ―― 「何がどう効いてこの順位になっているか」を
 * 常に説明可能にするため。
 *
 * 計算式(重みは初期値、将来調整可能):
 *   sort_score = (
 *       impact.score()        * 0.35 +   // 影響度: 高いほど優先
 *       effort.easeScore()    * 0.25 +   // 工数: 小さいほど優先(高影響・小工数を最優先)
 *       categoryWeightRatio   * 0.15 +   // カテゴリの重要度(配点の大きさ)
 *       metricWeightRatio     * 0.10 +   // 項目自体の相対重要度
 *       competitorGap         * 0.10 +   // 競合に対する遅れの大きさ(比較コンテキストが無ければ0)
 *       confidence            * 0.05     // confidenceが低い提案は順位を下げる
 *   ) * 100
 */
class RecommendationPriorityCalculator
{
    private const IMPACT_WEIGHT = 0.35;

    private const EFFORT_WEIGHT = 0.25;

    private const CATEGORY_WEIGHT_WEIGHT = 0.15;

    private const METRIC_WEIGHT_WEIGHT = 0.10;

    private const COMPETITOR_GAP_WEIGHT = 0.10;

    private const CONFIDENCE_WEIGHT = 0.05;

    /**
     * @param  float  $categoryWeight  CategoryDefinition.weight (0-100想定)
     * @param  float  $metricWeight  MetricDefinition.weight (相対重要度、通常0-10程度)
     * @param  float  $competitorGap  0.0(差なし)〜1.0(大きく劣る)。比較コンテキストが無ければ0。
     * @param  float  $confidence  0.0〜1.0
     */
    public function calculate(
        RecommendationImpact $impact,
        RecommendationEffort $effort,
        float $categoryWeight,
        float $metricWeight,
        float $competitorGap = 0.0,
        float $confidence = 1.0,
    ): float {
        $categoryWeightRatio = max(0.0, min(1.0, $categoryWeight / 100));
        $metricWeightRatio = max(0.0, min(1.0, $metricWeight / 10));
        $competitorGap = max(0.0, min(1.0, $competitorGap));
        $confidence = max(0.0, min(1.0, $confidence));

        $score =
            $impact->score() * self::IMPACT_WEIGHT
            + $effort->easeScore() * self::EFFORT_WEIGHT
            + $categoryWeightRatio * self::CATEGORY_WEIGHT_WEIGHT
            + $metricWeightRatio * self::METRIC_WEIGHT_WEIGHT
            + $competitorGap * self::COMPETITOR_GAP_WEIGHT
            + $confidence * self::CONFIDENCE_WEIGHT;

        return round($score * 100, 2);
    }
}
