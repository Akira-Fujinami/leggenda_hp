<?php

namespace App\Services\History;

use App\Enums\AnalysisStatus;
use App\Enums\RecommendationStatus;
use App\Models\Analysis;
use App\Models\CategoryDefinition;
use App\Models\MetricDefinition;
use App\Models\Recommendation;
use App\Models\User;
use App\Models\WebsiteAnalysis;
use App\Services\Scoring\OverallScoreCalculator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * 同一Projectの過去Analysisとの差分比較。
 * 対象は同じProjectのcompleted/partial Analysisのみ ―― 他Projectの
 * Analysisを指定された場合は明示的に拒否する。
 */
class HistoryComparisonService
{
    private const COMPARABLE_STATUSES = [AnalysisStatus::Completed, AnalysisStatus::Partial];

    public function __construct(
        private readonly OverallScoreCalculator $scoreCalculator,
        private readonly MetricValueDiffClassifier $diffClassifier,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function compare(Analysis $current, ?int $previousAnalysisId, User $user): array
    {
        $previous = $this->resolvePreviousAnalysis($current, $previousAnalysisId);

        if ($previous === null) {
            return [
                'current' => $this->analysisSummary($current),
                'previous' => null,
                'coverage_rate_diff_warning' => false,
                'sites' => [],
            ];
        }

        $current->loadMissing(['websiteAnalyses.website', 'websiteAnalyses.metricResults.metricDefinition']);
        $previous->loadMissing(['websiteAnalyses.website', 'websiteAnalyses.metricResults.metricDefinition']);

        $categories = CategoryDefinition::query()->where('is_active', true)->get();
        $definitions = MetricDefinition::query()->where('is_active', true)->get()->keyBy('id');

        $currentBySite = $current->websiteAnalyses->keyBy('website_id');
        $previousBySite = $previous->websiteAnalyses->keyBy('website_id');
        $allWebsiteIds = $currentBySite->keys()->merge($previousBySite->keys())->unique();

        $sites = $allWebsiteIds->map(function ($websiteId) use ($currentBySite, $previousBySite, $categories, $definitions) {
            return $this->compareSite($websiteId, $currentBySite->get($websiteId), $previousBySite->get($websiteId), $categories, $definitions);
        })->values()->all();

        $coverageWarning = collect($sites)->contains(fn ($site) => $site['coverage_rate_diff_warning']);

        return [
            'current' => $this->analysisSummary($current),
            'previous' => $this->analysisSummary($previous),
            'coverage_rate_diff_warning' => $coverageWarning,
            'sites' => $sites,
        ];
    }

    private function resolvePreviousAnalysis(Analysis $current, ?int $previousAnalysisId): ?Analysis
    {
        if ($previousAnalysisId !== null) {
            $previous = Analysis::query()->find($previousAnalysisId);

            if ($previous === null || $previous->project_id !== $current->project_id) {
                throw ValidationException::withMessages([
                    'previous_analysis_id' => ['指定されたAnalysisは同じプロジェクトのものではありません。'],
                ]);
            }

            if (! in_array($previous->status, self::COMPARABLE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'previous_analysis_id' => ['指定されたAnalysisは完了していないため比較できません。'],
                ]);
            }

            return $previous;
        }

        return Analysis::query()
            ->where('project_id', $current->project_id)
            ->where('id', '!=', $current->id)
            ->whereIn('status', self::COMPARABLE_STATUSES)
            ->when($current->completed_at !== null, fn ($q) => $q->where('completed_at', '<', $current->completed_at))
            ->orderByDesc('completed_at')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function compareSite(
        int $websiteId,
        ?WebsiteAnalysis $currentWa,
        ?WebsiteAnalysis $previousWa,
        Collection $categories,
        Collection $definitions,
    ): array {
        $currentScore = $currentWa !== null ? $this->scoreCalculator->calculate($categories, $currentWa->metricResults) : null;
        $previousScore = $previousWa !== null ? $this->scoreCalculator->calculate($categories, $previousWa->metricResults) : null;

        $website = $currentWa?->website ?? $previousWa?->website;

        $categoryDeltas = [];
        if ($currentScore !== null && $previousScore !== null) {
            foreach ($currentScore->categoryScores as $categoryScore) {
                $previousCategoryScore = $previousScore->categoryScores->firstWhere('key', $categoryScore->key);
                $categoryDeltas[] = [
                    'key' => $categoryScore->key,
                    'name' => $categoryScore->name,
                    'current_score' => $categoryScore->score,
                    'previous_score' => $previousCategoryScore?->score,
                    'delta' => $previousCategoryScore !== null ? round($categoryScore->score - $previousCategoryScore->score, 2) : null,
                ];
            }
        }

        $metricDeltas = ($currentWa !== null && $previousWa !== null)
            ? $this->compareMetrics($currentWa, $previousWa, $definitions)
            : [];

        $recommendationDiff = ($currentWa !== null && $previousWa !== null)
            ? $this->compareRecommendations($currentWa, $previousWa)
            : ['added' => [], 'resolved' => [], 'continued' => []];

        $coverageDiffWarning = $currentScore !== null && $previousScore !== null
            && abs($currentScore->coverageRate - $previousScore->coverageRate) >= 30;

        return [
            'website_id' => $websiteId,
            'website_name' => $website?->name,
            'present_in_current' => $currentWa !== null,
            'present_in_previous' => $previousWa !== null,
            'status_changed' => ($currentWa !== null && $previousWa !== null) ? $currentWa->status !== $previousWa->status : null,
            'current_status' => $currentWa?->status->value,
            'previous_status' => $previousWa?->status->value,
            'overall_score_delta' => ($currentScore !== null && $previousScore !== null) ? round($currentScore->overallScore - $previousScore->overallScore, 2) : null,
            'coverage_rate_delta' => ($currentScore !== null && $previousScore !== null) ? round($currentScore->coverageRate - $previousScore->coverageRate, 2) : null,
            'coverage_rate_diff_warning' => $coverageDiffWarning,
            'category_score_deltas' => $categoryDeltas,
            'metric_deltas' => $metricDeltas,
            'recommendation_added' => $recommendationDiff['added'],
            'recommendation_resolved' => $recommendationDiff['resolved'],
            'recommendation_continued' => $recommendationDiff['continued'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compareMetrics(WebsiteAnalysis $currentWa, WebsiteAnalysis $previousWa, Collection $definitions): array
    {
        $currentByDefinition = $currentWa->metricResults->keyBy('metric_definition_id');
        $previousByDefinition = $previousWa->metricResults->keyBy('metric_definition_id');

        $deltas = [];

        foreach ($definitions as $definition) {
            $currentResult = $currentByDefinition->get($definition->id);
            $previousResult = $previousByDefinition->get($definition->id);

            if ($currentResult === null || $previousResult === null) {
                continue;
            }

            $currentValue = $currentResult->normalized_value['value'] ?? null;
            $previousValue = $previousResult->normalized_value['value'] ?? null;

            $classification = $this->diffClassifier->classify($definition, $previousValue, $currentValue);

            if ($classification === 'unchanged') {
                continue;
            }

            $deltas[] = [
                'key' => $definition->key,
                'name' => $definition->name,
                'category_key' => $definition->category_key,
                'previous_value' => $previousValue,
                'current_value' => $currentValue,
                'classification' => $classification,
            ];
        }

        return $deltas;
    }

    /**
     * @return array{added: list<array<string, mixed>>, resolved: list<array<string, mixed>>, continued: list<array<string, mixed>>}
     */
    private function compareRecommendations(WebsiteAnalysis $currentWa, WebsiteAnalysis $previousWa): array
    {
        $currentRecs = Recommendation::query()
            ->with('metricResult')
            ->where('website_analysis_id', $currentWa->id)
            ->where('status', RecommendationStatus::Open)
            ->get()
            ->keyBy(fn (Recommendation $r) => $r->metricResult?->metric_definition_id ?? $r->id);

        $previousRecs = Recommendation::query()
            ->with('metricResult')
            ->where('website_analysis_id', $previousWa->id)
            ->where('status', RecommendationStatus::Open)
            ->get()
            ->keyBy(fn (Recommendation $r) => $r->metricResult?->metric_definition_id ?? $r->id);

        $added = $currentRecs->filter(fn ($r, $key) => ! $previousRecs->has($key))
            ->map(fn (Recommendation $r) => ['title' => $r->title, 'category_key' => $r->category_key, 'priority' => $r->priority->value])
            ->values()->all();

        $resolved = $previousRecs->filter(fn ($r, $key) => ! $currentRecs->has($key))
            ->map(fn (Recommendation $r) => ['title' => $r->title, 'category_key' => $r->category_key, 'priority' => $r->priority->value])
            ->values()->all();

        $continued = $currentRecs->filter(fn ($r, $key) => $previousRecs->has($key))
            ->map(fn (Recommendation $r) => ['title' => $r->title, 'category_key' => $r->category_key, 'priority' => $r->priority->value])
            ->values()->all();

        return ['added' => $added, 'resolved' => $resolved, 'continued' => $continued];
    }

    /**
     * @return array<string, mixed>
     */
    private function analysisSummary(Analysis $analysis): array
    {
        return [
            'id' => $analysis->id,
            'status' => $analysis->status->value,
            'started_at' => $analysis->started_at?->toIso8601String(),
            'completed_at' => $analysis->completed_at?->toIso8601String(),
        ];
    }
}
