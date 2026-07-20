<?php

namespace App\Services\AiAnalysis\Data;

use Illuminate\Support\Collection;

/**
 * AiAnalysisProviderへ渡す入力データ。
 *
 * 正規化・集計済みのデータのみを保持し、Raw HTML全文・Lighthouse生JSON・
 * Semrush Rawレスポンス・スクリーンショットのbase64は一切含めない
 * (AIへ渡してよい情報の境界を型で強制するための設計)。
 */
readonly class AiAnalysisInput
{
    /**
     * @param  Collection<int, AiCategoryScoreSnapshot>  $categoryScores
     * @param  Collection<int, AiMetricSnapshot>  $importantMetrics
     * @param  list<string>  $strengths
     * @param  list<string>  $weaknesses
     * @param  Collection<int, AiRecommendationSummary>  $recommendations
     * @param  Collection<int, AiCompetitorGap>  $competitorGaps
     * @param  list<string>  $unavailableMetrics
     * @param  list<string>  $errorMetrics
     */
    public function __construct(
        public int $projectId,
        public int $analysisId,
        public int $websiteAnalysisId,
        public ?string $websiteName,
        public ?string $websiteUrl,
        public ?string $industry,
        public ?string $purpose,
        public float $overallScore,
        public Collection $categoryScores,
        public Collection $importantMetrics,
        public array $strengths,
        public array $weaknesses,
        public Collection $recommendations,
        public Collection $competitorGaps,
        public float $coverageRate,
        public float $confidenceRate,
        public array $unavailableMetrics,
        public array $errorMetrics,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'analysis_id' => $this->analysisId,
            'website_analysis_id' => $this->websiteAnalysisId,
            'website_name' => $this->websiteName,
            'website_url' => $this->websiteUrl,
            'industry' => $this->industry,
            'purpose' => $this->purpose,
            'overall_score' => $this->overallScore,
            'category_scores' => $this->categoryScores->map(fn (AiCategoryScoreSnapshot $c) => $c->toArray())->values()->all(),
            'important_metrics' => $this->importantMetrics->map(fn (AiMetricSnapshot $m) => $m->toArray())->values()->all(),
            'strengths' => $this->strengths,
            'weaknesses' => $this->weaknesses,
            'recommendations' => $this->recommendations->map(fn (AiRecommendationSummary $r) => $r->toArray())->values()->all(),
            'competitor_gaps' => $this->competitorGaps->map(fn (AiCompetitorGap $g) => $g->toArray())->values()->all(),
            'coverage_rate' => $this->coverageRate,
            'confidence_rate' => $this->confidenceRate,
            'unavailable_metrics' => $this->unavailableMetrics,
            'error_metrics' => $this->errorMetrics,
        ];
    }
}
