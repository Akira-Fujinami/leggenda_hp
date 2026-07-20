<?php

namespace App\Services\AiAnalysis;

use App\Enums\MetricResultStatus;
use App\Enums\RecommendationStatus;
use App\Models\CategoryDefinition;
use App\Models\MetricDefinition;
use App\Models\Recommendation;
use App\Models\WebsiteAnalysis;
use App\Services\AiAnalysis\Data\AiAnalysisInput;
use App\Services\AiAnalysis\Data\AiCategoryScoreSnapshot;
use App\Services\AiAnalysis\Data\AiCompetitorGap;
use App\Services\AiAnalysis\Data\AiMetricSnapshot;
use App\Services\AiAnalysis\Data\AiRecommendationSummary;
use App\Services\Comparison\ComparisonCalculator;
use App\Services\Comparison\StrengthWeaknessExtractor;
use App\Services\Scoring\OverallScoreCalculator;
use App\Support\Comparison\SiteScoreEntry;
use App\Support\Scoring\CategoryScoreResult;

/**
 * 既存の採点基盤・比較基盤・Recommendationから、AiAnalysisProviderへ渡す
 * AiAnalysisInputを組み立てる。Raw HTML・Lighthouse生JSON・Semrush Raw
 * レスポンス・スクリーンショットは一切参照しない
 * (MetricResult.normalized_value /評価済みスコア / Recommendationの要約のみを使う)。
 *
 * まだこのFactoryをJob・画面から呼び出すコードは無い(Phase 3時点ではAI呼び出し
 * そのものは行わない)。次PhaseでAI分析Jobを追加する際の入力生成に使う想定。
 */
class AiAnalysisInputFactory
{
    private const int MAX_IMPORTANT_METRICS = 10;

    private const int MAX_RECOMMENDATIONS = 10;

    public function __construct(
        private readonly OverallScoreCalculator $scoreCalculator,
        private readonly ComparisonCalculator $comparisonCalculator,
        private readonly StrengthWeaknessExtractor $strengthWeaknessExtractor,
    ) {
    }

    public function build(WebsiteAnalysis $websiteAnalysis): AiAnalysisInput
    {
        $websiteAnalysis->loadMissing([
            'website',
            'analysis.project',
            'analysis.websiteAnalyses.website',
            'analysis.websiteAnalyses.metricResults.metricDefinition',
        ]);

        $analysis = $websiteAnalysis->analysis;
        $project = $analysis->project;

        $categories = CategoryDefinition::query()->where('is_active', true)->orderBy('display_order')->get();
        $definitions = MetricDefinition::query()->where('is_active', true)->orderBy('display_order')->get();

        $entries = $analysis->websiteAnalyses->map(fn ($wa) => new SiteScoreEntry(
            websiteAnalysis: $wa,
            score: $this->scoreCalculator->calculate($categories, $wa->metricResults),
        ));

        $targetEntry = $entries->first(fn (SiteScoreEntry $e) => $e->websiteAnalysis->id === $websiteAnalysis->id);

        if ($targetEntry === null) {
            throw new \RuntimeException('指定されたWebsiteAnalysisが、所属するAnalysisのwebsiteAnalyses一覧内に見つかりません。');
        }

        $resultsByWebsiteAnalysis = $analysis->websiteAnalyses->mapWithKeys(fn ($wa) => [$wa->id => $wa->metricResults]);

        // 自社/競合比較用の内訳(strengths/weaknesses抽出に必要)。
        // 比較APIのように「自社サイトとの差分」を出す必要はないため、primaryはnullで渡す。
        $categoryComparisons = $this->comparisonCalculator->compareCategories($entries, $categories, null);
        $metricComparisons = $this->comparisonCalculator->compareMetrics($entries, $definitions, $resultsByWebsiteAnalysis, null);

        $recommendations = Recommendation::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('status', RecommendationStatus::Open)
            ->orderByDesc('sort_score')
            ->limit(self::MAX_RECOMMENDATIONS)
            ->get();

        $strengthsAndWeaknesses = $this->strengthWeaknessExtractor->extract(
            $websiteAnalysis->id,
            $categoryComparisons,
            $metricComparisons,
            $recommendations,
        );

        $categoryScores = $targetEntry->score->categoryScores->map(
            fn (CategoryScoreResult $category) => new AiCategoryScoreSnapshot(
                key: $category->key,
                name: $category->name,
                score: $category->score,
                maxScore: $category->configuredMaxScore,
                coverageRate: $category->coverageRate,
            )
        );

        $importantMetrics = $websiteAnalysis->metricResults
            ->filter(fn ($result) => $result->status === MetricResultStatus::Success && $result->metricDefinition?->is_active)
            ->sortByDesc(fn ($result) => (float) ($result->metricDefinition->weight ?? 0))
            ->take(self::MAX_IMPORTANT_METRICS)
            ->map(fn ($result) => new AiMetricSnapshot(
                key: $result->metricDefinition->key,
                name: $result->metricDefinition->name,
                categoryKey: $result->metricDefinition->category_key,
                value: $result->normalized_value['value'] ?? null,
                unit: $result->metricDefinition->unit,
                confidence: (float) ($result->confidence ?? 1.0),
            ))
            ->values();

        $unavailableMetrics = $this->metricLabelsByStatus($websiteAnalysis, MetricResultStatus::Unavailable);
        $errorMetrics = $this->metricLabelsByStatus($websiteAnalysis, MetricResultStatus::Error);

        $recommendationSummaries = $recommendations->map(
            fn (Recommendation $recommendation) => new AiRecommendationSummary(
                title: $recommendation->title,
                categoryKey: $recommendation->category_key,
                priority: $recommendation->priority->value,
                impact: $recommendation->impact->value,
                effort: $recommendation->effort->value,
            )
        );

        $competitorGaps = $entries
            ->reject(fn (SiteScoreEntry $entry) => $entry->websiteAnalysis->id === $websiteAnalysis->id)
            ->map(fn (SiteScoreEntry $entry) => new AiCompetitorGap(
                competitorName: $entry->websiteAnalysis->website?->name ?? "サイト #{$entry->websiteAnalysis->website_id}",
                scoreGap: round($entry->score->overallScore - $targetEntry->score->overallScore, 2),
            ))
            ->values();

        return new AiAnalysisInput(
            projectId: $project->id,
            analysisId: $analysis->id,
            websiteAnalysisId: $websiteAnalysis->id,
            websiteName: $websiteAnalysis->website?->name,
            websiteUrl: $websiteAnalysis->website?->normalized_url,
            industry: $project->industry,
            purpose: $project->purpose,
            overallScore: $targetEntry->score->overallScore,
            categoryScores: $categoryScores,
            importantMetrics: $importantMetrics,
            strengths: array_map(fn (array $item) => (string) $item['label'], $strengthsAndWeaknesses['strengths']),
            weaknesses: array_map(fn (array $item) => (string) $item['label'], $strengthsAndWeaknesses['weaknesses']),
            recommendations: $recommendationSummaries,
            competitorGaps: $competitorGaps,
            coverageRate: $targetEntry->score->coverageRate,
            confidenceRate: $targetEntry->score->confidenceRate,
            unavailableMetrics: $unavailableMetrics,
            errorMetrics: $errorMetrics,
        );
    }

    /**
     * @return list<string>
     */
    private function metricLabelsByStatus(WebsiteAnalysis $websiteAnalysis, MetricResultStatus $status): array
    {
        return $websiteAnalysis->metricResults
            ->filter(fn ($result) => $result->status === $status)
            ->map(fn ($result) => $result->metricDefinition?->name ?? $result->metricDefinition?->key ?? 'unknown')
            ->unique()
            ->values()
            ->all();
    }
}
