<?php

namespace App\Support\Scoring;

use Illuminate\Support\Collection;

readonly class WebsiteScoreResult
{
    /**
     * @param  Collection<int, CategoryScoreResult>  $categoryScores
     * @param  array{success: int, not_found: int, unavailable: int, error: int, not_applicable: int}  $metricSummary
     */
    public function __construct(
        public float $overallScore,
        public int $displayScore,
        public float $availableScore,
        public float $configuredMaxScore,
        public float $coverageRate,
        public float $confidenceRate,
        public Collection $categoryScores,
        public array $metricSummary,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'overall_score' => round($this->overallScore, 2),
            'display_score' => $this->displayScore,
            'available_score' => round($this->availableScore, 2),
            'configured_max_score' => round($this->configuredMaxScore, 2),
            'coverage_rate' => round($this->coverageRate, 2),
            'confidence_rate' => round($this->confidenceRate, 2),
            'category_scores' => $this->categoryScores->map(fn (CategoryScoreResult $c) => $c->toArray())->values()->all(),
            'metric_summary' => $this->metricSummary,
        ];
    }
}
