<?php

namespace Tests\Unit\Recommendation;

use App\Enums\RecommendationEffort;
use App\Enums\RecommendationImpact;
use App\Services\Recommendation\RecommendationPriorityCalculator;
use Tests\TestCase;

class RecommendationPriorityCalculatorTest extends TestCase
{
    private RecommendationPriorityCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new RecommendationPriorityCalculator;
    }

    public function test_high_impact_small_effort_scores_higher_than_low_impact_large_effort(): void
    {
        $highImpactSmallEffort = $this->calculator->calculate(
            RecommendationImpact::High, RecommendationEffort::Small, categoryWeight: 20, metricWeight: 5,
        );

        $lowImpactLargeEffort = $this->calculator->calculate(
            RecommendationImpact::Low, RecommendationEffort::Large, categoryWeight: 20, metricWeight: 5,
        );

        $this->assertGreaterThan($lowImpactLargeEffort, $highImpactSmallEffort);
    }

    public function test_lower_confidence_lowers_the_score(): void
    {
        $highConfidence = $this->calculator->calculate(
            RecommendationImpact::Medium, RecommendationEffort::Medium, categoryWeight: 15, metricWeight: 3, confidence: 1.0,
        );

        $lowConfidence = $this->calculator->calculate(
            RecommendationImpact::Medium, RecommendationEffort::Medium, categoryWeight: 15, metricWeight: 3, confidence: 0.2,
        );

        $this->assertGreaterThan($lowConfidence, $highConfidence);
    }

    public function test_larger_competitor_gap_raises_the_score(): void
    {
        $noGap = $this->calculator->calculate(
            RecommendationImpact::Medium, RecommendationEffort::Medium, categoryWeight: 15, metricWeight: 3, competitorGap: 0.0,
        );

        $largeGap = $this->calculator->calculate(
            RecommendationImpact::Medium, RecommendationEffort::Medium, categoryWeight: 15, metricWeight: 3, competitorGap: 1.0,
        );

        $this->assertGreaterThan($noGap, $largeGap);
    }

    public function test_score_is_clamped_within_a_reasonable_range(): void
    {
        $max = $this->calculator->calculate(RecommendationImpact::High, RecommendationEffort::Small, categoryWeight: 100, metricWeight: 100, competitorGap: 100, confidence: 100);

        $this->assertLessThanOrEqual(100, $max);
        $this->assertGreaterThan(0, $max);
    }
}
