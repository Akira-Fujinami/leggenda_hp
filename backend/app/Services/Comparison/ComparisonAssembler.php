<?php

namespace App\Services\Comparison;

use App\Enums\MetricResultStatus;
use App\Models\Analysis;
use App\Models\CategoryDefinition;
use App\Models\ExternalDataSnapshot;
use App\Models\MetricDefinition;
use App\Models\Recommendation;
use App\Services\Scoring\OverallScoreCalculator;
use App\Support\Comparison\SiteScoreEntry;

/**
 * GET /api/analyses/{analysis}/comparison のレスポンスを組み立てる。
 * Raw HTML・Lighthouse生JSON・Semrush Rawレスポンスは一切含めない。
 */
class ComparisonAssembler
{
    public function __construct(
        private readonly OverallScoreCalculator $scoreCalculator,
        private readonly RankingCalculator $rankingCalculator,
        private readonly ComparisonCalculator $comparisonCalculator,
        private readonly StrengthWeaknessExtractor $strengthWeaknessExtractor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function assemble(Analysis $analysis): array
    {
        $analysis->loadMissing([
            'websiteAnalyses.website',
            'websiteAnalyses.metricResults.metricDefinition',
        ]);

        $categories = CategoryDefinition::query()->where('is_active', true)->orderBy('display_order')->get();
        $definitions = MetricDefinition::query()->where('is_active', true)->orderBy('display_order')->get();

        $entries = $analysis->websiteAnalyses->map(fn ($wa) => new SiteScoreEntry(
            websiteAnalysis: $wa,
            score: $this->scoreCalculator->calculate($categories, $wa->metricResults),
        ));

        $primaryEntry = $entries->first(fn (SiteScoreEntry $e) => (bool) $e->websiteAnalysis->website?->is_primary);

        $ranking = $this->rankingCalculator->rank($entries);

        $resultsByWebsiteAnalysis = $analysis->websiteAnalyses->mapWithKeys(
            fn ($wa) => [$wa->id => $wa->metricResults]
        );

        $categoryComparisons = $this->comparisonCalculator->compareCategories($entries, $categories, $primaryEntry);
        $metricComparisons = $this->comparisonCalculator->compareMetrics($entries, $definitions, $resultsByWebsiteAnalysis, $primaryEntry);

        $recommendationsByWebsiteAnalysis = Recommendation::query()
            ->whereIn('website_analysis_id', $analysis->websiteAnalyses->pluck('id'))
            ->get()
            ->groupBy('website_analysis_id');

        $externalSeoSnapshots = ExternalDataSnapshot::query()
            ->whereIn('website_analysis_id', $analysis->websiteAnalyses->pluck('id'))
            ->where('operation', 'domain_overview')
            ->get()
            ->keyBy('website_analysis_id');

        $strengths = [];
        $weaknesses = [];
        $dataQuality = [];
        $externalSeo = [];

        foreach ($entries as $entry) {
            $waId = $entry->websiteAnalysis->id;
            $recs = $recommendationsByWebsiteAnalysis->get($waId) ?? collect();

            $sw = $this->strengthWeaknessExtractor->extract($waId, $categoryComparisons, $metricComparisons, $recs);
            $strengths[] = ['website_analysis_id' => $waId, 'items' => $sw['strengths']];
            $weaknesses[] = ['website_analysis_id' => $waId, 'items' => $sw['weaknesses']];
            $dataQuality[] = $this->buildDataQuality($entry);
            $externalSeo[] = $this->buildExternalSeoInfo($waId, $externalSeoSnapshots->get($waId));
        }

        return [
            'analysis' => [
                'id' => $analysis->id,
                'status' => $analysis->status->value,
                'started_at' => $analysis->started_at?->toIso8601String(),
                'completed_at' => $analysis->completed_at?->toIso8601String(),
            ],
            'primary_website_analysis_id' => $primaryEntry?->websiteAnalysis->id,
            'ranking' => $ranking->map(fn ($ranked) => [
                'rank' => $ranked->rank,
                'website_analysis_id' => $ranked->entry->websiteAnalysis->id,
                'website_id' => $ranked->entry->websiteAnalysis->website_id,
                'website_name' => $ranked->entry->websiteAnalysis->website?->name,
                'is_primary' => (bool) $ranked->entry->websiteAnalysis->website?->is_primary,
                'overall_score' => $ranked->entry->score->overallScore,
                'display_score' => $ranked->entry->score->displayScore,
                'coverage_rate' => $ranked->entry->score->coverageRate,
                'confidence_rate' => $ranked->entry->score->confidenceRate,
                'low_data_warning' => $ranked->lowDataWarning,
                'score_gap_vs_primary' => $primaryEntry !== null && $primaryEntry->websiteAnalysis->id !== $ranked->entry->websiteAnalysis->id
                    ? round($ranked->entry->score->overallScore - $primaryEntry->score->overallScore, 2)
                    : null,
            ])->values()->all(),
            'categories' => $categoryComparisons,
            'metrics' => $metricComparisons,
            'strengths' => $strengths,
            'weaknesses' => $weaknesses,
            'data_quality' => $dataQuality,
            'external_seo' => $externalSeo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExternalSeoInfo(int $websiteAnalysisId, ?ExternalDataSnapshot $snapshot): array
    {
        if ($snapshot === null) {
            return [
                'website_analysis_id' => $websiteAnalysisId,
                'provider' => null,
                'is_mock' => false,
                'status' => 'unavailable',
                'database' => null,
                'requested_domain' => null,
                'normalized_domain' => null,
                'scope' => null,
                'fetched_at' => null,
                'cache_hit' => false,
                'error_code' => null,
                'error_message' => null,
            ];
        }

        return [
            'website_analysis_id' => $websiteAnalysisId,
            'provider' => $snapshot->provider,
            'is_mock' => (bool) $snapshot->is_mock,
            'status' => $snapshot->status,
            'database' => $snapshot->database,
            'requested_domain' => $snapshot->requested_domain,
            'normalized_domain' => $snapshot->domain,
            'scope' => $snapshot->scope,
            'fetched_at' => $snapshot->fetched_at?->toIso8601String(),
            'cache_hit' => $snapshot->source_snapshot_id !== null,
            'error_code' => $snapshot->error_code,
            'error_message' => $snapshot->error_message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDataQuality(SiteScoreEntry $entry): array
    {
        $summary = $entry->score->metricSummary;
        $results = $entry->websiteAnalysis->metricResults;

        $mockCount = $results->filter(fn ($r) => (bool) ($r->evidence['is_mock'] ?? false))->count();
        $externalCount = $results->filter(fn ($r) => $r->metricDefinition?->source_type === 'semrush')->count();
        $lastFetchedAt = $results->max('measured_at');

        return [
            'website_analysis_id' => $entry->websiteAnalysis->id,
            'coverage_rate' => $entry->score->coverageRate,
            'confidence_rate' => $entry->score->confidenceRate,
            'measured_count' => $summary['success'],
            'external_count' => $externalCount,
            'unavailable_count' => $summary['unavailable'],
            'error_count' => $summary['error'],
            'scored_unavailable_count' => $summary['scored_unavailable'],
            'informational_unavailable_count' => $summary['informational_unavailable'],
            'mock_count' => $mockCount,
            'last_fetched_at' => $lastFetchedAt?->toIso8601String(),
            'warnings' => $this->buildWarnings($entry, $mockCount),
        ];
    }

    /**
     * @return list<string>
     */
    private function buildWarnings(SiteScoreEntry $entry, int $mockCount): array
    {
        $warnings = [];

        if ($entry->score->coverageRate < 70) {
            $warnings[] = 'coverage_below_70';
        }

        if ($entry->score->confidenceRate < 70) {
            $warnings[] = 'confidence_below_70';
        }

        if ($mockCount > 0) {
            $warnings[] = 'contains_mock_data';
        }

        $lighthouseFailed = $entry->websiteAnalysis->metricResults->contains(
            fn ($r) => $r->metricDefinition?->key === 'lighthouse_performance' && $r->status === MetricResultStatus::Unavailable
        );
        if ($lighthouseFailed) {
            $warnings[] = 'lighthouse_failed';
        }

        if ($entry->websiteAnalysis->status->value === 'partial') {
            $warnings[] = 'partial_html_fetch';
        }

        return $warnings;
    }
}
