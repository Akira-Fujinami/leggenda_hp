<?php

namespace App\Services\Scoring;

use App\Models\CategoryDefinition;
use App\Support\Scoring\CategoryScoreResult;
use App\Support\Scoring\MetricScoreOutcome;
use Illuminate\Support\Collection;

/**
 * カテゴリ単位のスコアを算出する。
 * configured_max_scoreは必ずCategoryDefinition.weightから取得し、
 * コード内にハードコードしない。
 */
class CategoryScoreCalculator
{
    public function __construct(private readonly CoverageCalculator $coverage)
    {
    }

    /**
     * @param  Collection<int, CategoryDefinition>  $activeCategories
     * @param  Collection<int, \App\Models\MetricResult>  $results  metricDefinition読み込み済み
     * @param  array<int, MetricScoreOutcome>  $outcomesByResultId  MetricResult::id => MetricScoreOutcome
     * @return Collection<int, CategoryScoreResult>
     */
    public function calculate(Collection $activeCategories, Collection $results, array $outcomesByResultId): Collection
    {
        $scoreByCategory = [];
        $availableByCategory = [];

        foreach ($results as $result) {
            $definition = $result->metricDefinition;
            $outcome = $outcomesByResultId[$result->id] ?? null;

            if ($definition === null || $outcome === null || ! $outcome->countsTowardScore) {
                continue;
            }

            $categoryKey = $definition->category_key;
            $scoreByCategory[$categoryKey] = ($scoreByCategory[$categoryKey] ?? 0.0) + (float) $outcome->score;
            $availableByCategory[$categoryKey] = ($availableByCategory[$categoryKey] ?? 0.0) + (float) $outcome->maxScore;
        }

        return $activeCategories
            ->sortBy('display_order')
            ->map(function (CategoryDefinition $category) use ($scoreByCategory, $availableByCategory) {
                $configuredMax = (float) $category->weight;
                $available = $availableByCategory[$category->key] ?? 0.0;
                $score = $scoreByCategory[$category->key] ?? 0.0;

                return new CategoryScoreResult(
                    key: $category->key,
                    name: $category->name,
                    score: $score,
                    maxAvailableScore: $available,
                    configuredMaxScore: $configuredMax,
                    coverageRate: $this->coverage->rate($available, $configuredMax),
                );
            })
            ->values();
    }
}
