<?php

namespace App\Services\Comparison;

use App\Support\Comparison\SiteScoreEntry;
use Illuminate\Support\Collection;

/**
 * 比較API向けのカテゴリ比較・Metric比較テーブルを組み立てる。
 * 自社サイト(is_primary=true)が存在する場合のみgap(差分)を算出し、
 * 存在しない場合はnullのまま返す(横並び比較自体は可能)。
 */
class ComparisonCalculator
{
    /**
     * @param  Collection<int, SiteScoreEntry>  $entries
     * @param  Collection<int, \App\Models\CategoryDefinition>  $categories
     * @return list<array<string, mixed>>
     */
    public function compareCategories(Collection $entries, Collection $categories, ?SiteScoreEntry $primary): array
    {
        return $categories->sortBy('display_order')->map(function ($category) use ($entries, $primary) {
            $primaryScore = $primary?->categoryScore($category->key);

            return [
                'key' => $category->key,
                'name' => $category->name,
                'configured_max_score' => round((float) $category->weight, 2),
                'sites' => $entries->map(function (SiteScoreEntry $entry) use ($category, $primaryScore) {
                    $categoryResult = $entry->score->categoryScores->firstWhere('key', $category->key);
                    $score = $categoryResult?->score ?? 0.0;

                    return [
                        'website_analysis_id' => $entry->websiteAnalysis->id,
                        'score' => round($score, 2),
                        'max_available_score' => round($categoryResult?->maxAvailableScore ?? 0.0, 2),
                        'coverage_rate' => round($categoryResult?->coverageRate ?? 0.0, 2),
                        'gap_vs_primary' => $primaryScore !== null ? round($score - $primaryScore, 2) : null,
                    ];
                })->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, SiteScoreEntry>  $entries
     * @param  Collection<int, \App\Models\MetricDefinition>  $definitions
     * @param  Collection<int, \App\Models\MetricResult>  $resultsByWebsiteAnalysis  website_analysis_id => Collection<MetricResult>のキー付きコレクション
     * @return list<array<string, mixed>>
     */
    public function compareMetrics(Collection $entries, Collection $definitions, Collection $resultsByWebsiteAnalysis, ?SiteScoreEntry $primary): array
    {
        $primaryResults = $primary !== null ? ($resultsByWebsiteAnalysis->get($primary->websiteAnalysis->id) ?? collect()) : collect();

        return $definitions->sortBy('display_order')->map(function ($definition) use ($entries, $resultsByWebsiteAnalysis, $primaryResults) {
            $primaryResult = $primaryResults->firstWhere('metric_definition_id', $definition->id);
            $primaryValue = $this->numericValueOf($primaryResult);

            return [
                'key' => $definition->key,
                'name' => $definition->name,
                'category_key' => $definition->category_key,
                'value_type' => $definition->value_type,
                'unit' => $definition->unit,
                'source_type' => $definition->source_type,
                'higher_is_better' => (bool) $definition->higher_is_better,
                'sites' => $entries->map(function (SiteScoreEntry $entry) use ($definition, $resultsByWebsiteAnalysis, $primaryValue) {
                    $results = $resultsByWebsiteAnalysis->get($entry->websiteAnalysis->id) ?? collect();
                    $result = $results->firstWhere('metric_definition_id', $definition->id);
                    $value = $this->numericValueOf($result);

                    return [
                        'website_analysis_id' => $entry->websiteAnalysis->id,
                        'status' => $result?->status?->value,
                        'value' => $result?->normalized_value['value'] ?? null,
                        'confidence' => $result?->confidence !== null ? (float) $result->confidence : null,
                        'evidence' => $result?->evidence,
                        'measured_at' => $result?->measured_at?->toIso8601String(),
                        'error_code' => $result?->error_code,
                        'error_message' => $result?->error_message,
                        'is_mock' => (bool) ($result?->evidence['is_mock'] ?? false),
                        'gap_vs_primary' => ($primaryValue !== null && $value !== null) ? round($value - $primaryValue, 4) : null,
                    ];
                })->values()->all(),
            ];
        })->values()->all();
    }

    private function numericValueOf($result): ?float
    {
        $value = $result?->normalized_value['value'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
