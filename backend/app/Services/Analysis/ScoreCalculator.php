<?php

namespace App\Services\Analysis;

use App\Enums\MetricResultStatus;
use Illuminate\Support\Collection;

/**
 * 基本スコアの計算。未取得・エラーの項目は0点にせず、計算対象(分母・分子)から
 * 除外することで「取得できた項目だけを基に正規化」した総合点を算出する。
 * 配点はMetricDefinitionから取得し、ハードコードしない。
 */
class ScoreCalculator
{
    /**
     * @param  Collection<int, \App\Models\MetricResult>  $results  score(load済みのmetricDefinitionを含む)
     * @param  Collection<int, \App\Models\MetricDefinition>  $activeDefinitions  is_active=trueの全定義
     * @return array{
     *     total_score: float,
     *     max_available_score: float,
     *     coverage_rate: float,
     *     failed_metric_count: int,
     *     unavailable_metric_count: int,
     *     categories: array<string, array{score: float, available_max_score: float, max_score: float}>,
     * }
     */
    public function calculate(Collection $results, Collection $activeDefinitions): array
    {
        $fullMaxScoreByCategory = [];
        foreach ($activeDefinitions as $definition) {
            $fullMaxScoreByCategory[$definition->category] ??= 0.0;
            $fullMaxScoreByCategory[$definition->category] += (float) $definition->max_score;
        }
        $fullMaxScore = array_sum($fullMaxScoreByCategory);

        $totalScore = 0.0;
        $maxAvailableScore = 0.0;
        $failedCount = 0;
        $unavailableCount = 0;
        $categories = [];

        foreach ($fullMaxScoreByCategory as $category => $max) {
            $categories[$category] = ['score' => 0.0, 'available_max_score' => 0.0, 'max_score' => $max];
        }

        foreach ($results as $result) {
            $definition = $result->metricDefinition;
            if ($definition === null || ! $definition->is_active) {
                continue;
            }

            $category = $definition->category;

            if ($result->status === MetricResultStatus::Success) {
                $score = (float) ($result->score ?? 0);
                $maxScore = (float) ($result->max_score ?? $definition->max_score);

                $totalScore += $score;
                $maxAvailableScore += $maxScore;
                $categories[$category]['score'] += $score;
                $categories[$category]['available_max_score'] += $maxScore;
            } elseif ($result->status === MetricResultStatus::Error) {
                $failedCount++;
            } else {
                // not_found / not_applicable / unavailable
                $unavailableCount++;
            }
        }

        return [
            'total_score' => round($totalScore, 2),
            'max_available_score' => round($maxAvailableScore, 2),
            'coverage_rate' => $fullMaxScore > 0 ? round($maxAvailableScore / $fullMaxScore, 4) : 0.0,
            'failed_metric_count' => $failedCount,
            'unavailable_metric_count' => $unavailableCount,
            'categories' => $categories,
        ];
    }
}
