<?php

namespace App\Services\Scoring;

use App\Enums\MetricResultStatus;
use App\Models\CategoryDefinition;
use App\Support\Scoring\WebsiteScoreResult;
use Illuminate\Support\Collection;

/**
 * サイト単位の総合スコアを算出する。overall_scoreは「取得できた項目だけで
 * 100点換算した値」ではなく、各カテゴリの実測分を配点通りの尺度のまま
 * 合算した値(=available_scoreの範囲内で実際に稼いだ点数)。
 * coverage_rateを別途表示することで、取得率が低いサイトが高得点に
 * 見えすぎないようにする。
 */
class OverallScoreCalculator
{
    public function __construct(
        private readonly MetricScorer $scorer,
        private readonly CategoryScoreCalculator $categoryCalculator,
        private readonly CoverageCalculator $coverage,
        private readonly ConfidenceCalculator $confidence,
    ) {
    }

    /**
     * @param  Collection<int, CategoryDefinition>  $activeCategories
     * @param  Collection<int, \App\Models\MetricResult>  $results  metricDefinition読み込み済み
     */
    public function calculate(Collection $activeCategories, Collection $results): WebsiteScoreResult
    {
        $outcomesByResultId = [];
        $summary = ['success' => 0, 'not_found' => 0, 'unavailable' => 0, 'error' => 0, 'not_applicable' => 0];

        foreach ($results as $result) {
            $definition = $result->metricDefinition;

            if ($definition === null) {
                continue;
            }

            $outcomesByResultId[$result->id] = $this->scorer->score($definition, $result);

            $key = match ($result->status) {
                MetricResultStatus::Success => 'success',
                MetricResultStatus::NotFound => 'not_found',
                MetricResultStatus::Unavailable => 'unavailable',
                MetricResultStatus::Error => 'error',
                MetricResultStatus::NotApplicable => 'not_applicable',
            };
            $summary[$key]++;
        }

        $categoryScores = $this->categoryCalculator->calculate($activeCategories, $results, $outcomesByResultId);

        $availableScore = (float) $categoryScores->sum('maxAvailableScore');
        $overallScore = (float) $categoryScores->sum('score');
        $configuredMaxScore = (float) $activeCategories->sum('weight');

        return new WebsiteScoreResult(
            overallScore: $overallScore,
            displayScore: (int) round($overallScore),
            availableScore: $availableScore,
            configuredMaxScore: $configuredMaxScore,
            coverageRate: $this->coverage->rate($availableScore, $configuredMaxScore),
            confidenceRate: $this->confidence->rate($results, $outcomesByResultId),
            categoryScores: $categoryScores,
            metricSummary: $summary,
        );
    }
}
