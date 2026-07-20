<?php

namespace App\Services\Scoring;

use App\Support\Scoring\MetricScoreOutcome;
use Illuminate\Support\Collection;

/**
 * サイト単位のconfidence_rate(0-100)を算出する。
 * 採点の分母に含まれた(=MetricScoreOutcome::countsTowardScore)項目のみを対象に、
 * その項目の配点(max_score)で重み付けした平均confidenceを返す。
 * confidenceが未設定(null)の場合は1.0(実測相当)として扱う。
 */
class ConfidenceCalculator
{
    /**
     * @param  Collection<int, \App\Models\MetricResult>  $results
     * @param  array<int, MetricScoreOutcome>  $outcomesByResultId
     */
    public function rate(Collection $results, array $outcomesByResultId): float
    {
        $weightedSum = 0.0;
        $totalWeight = 0.0;

        foreach ($results as $result) {
            $outcome = $outcomesByResultId[$result->id] ?? null;

            if ($outcome === null || ! $outcome->countsTowardScore) {
                continue;
            }

            $weight = (float) ($outcome->maxScore ?? 0.0);
            if ($weight <= 0.0) {
                $weight = 1.0;
            }

            $confidence = $result->confidence !== null ? (float) $result->confidence : 1.0;

            $weightedSum += $confidence * $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        return round(($weightedSum / $totalWeight) * 100, 2);
    }
}
