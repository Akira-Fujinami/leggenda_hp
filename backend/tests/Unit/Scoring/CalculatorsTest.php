<?php

namespace Tests\Unit\Scoring;

use App\Enums\MetricResultStatus;
use App\Models\CategoryDefinition;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Services\Scoring\CategoryScoreCalculator;
use App\Services\Scoring\CoverageCalculator;
use App\Services\Scoring\MetricScorer;
use App\Services\Scoring\OverallScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalculatorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_coverage_calculator_handles_zero_configured_max(): void
    {
        $calculator = new CoverageCalculator;

        $this->assertSame(0.0, $calculator->rate(10, 0));
        $this->assertSame(50.0, $calculator->rate(5, 10));
        $this->assertSame(100.0, $calculator->rate(10, 10));
    }

    public function test_category_score_calculator_sums_only_counted_metrics(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'technical_seo', 'weight' => 20]);
        $counted = MetricDefinition::factory()->create(['category_key' => 'technical_seo', 'scoring_type' => 'boolean', 'max_score' => 12]);
        $excluded = MetricDefinition::factory()->create(['category_key' => 'technical_seo', 'scoring_type' => 'boolean', 'max_score' => 8]);

        MetricResult::factory()->create([
            'metric_definition_id' => $counted->id,
            'status' => MetricResultStatus::Success,
            'normalized_value' => ['value' => true],
        ]);
        MetricResult::factory()->create([
            'metric_definition_id' => $excluded->id,
            'status' => MetricResultStatus::Unavailable,
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $scorer = new MetricScorer;
        $outcomes = [];
        foreach ($results as $result) {
            $outcomes[$result->id] = $scorer->score($result->metricDefinition, $result);
        }

        $categoryScores = (new CategoryScoreCalculator(new CoverageCalculator))
            ->calculate(collect([$category]), $results, $outcomes);

        $technicalSeo = $categoryScores->first();
        $this->assertSame('technical_seo', $technicalSeo->key);
        $this->assertSame(12.0, $technicalSeo->score);
        $this->assertSame(12.0, $technicalSeo->maxAvailableScore);
        $this->assertSame(20.0, $technicalSeo->configuredMaxScore);
        $this->assertSame(60.0, $technicalSeo->coverageRate); // 12/20
    }

    public function test_overall_score_calculator_matches_expected_shape(): void
    {
        $techSeo = CategoryDefinition::factory()->create(['key' => 'technical_seo', 'weight' => 20, 'display_order' => 1]);
        $content = CategoryDefinition::factory()->create(['key' => 'content', 'weight' => 15, 'display_order' => 2]);

        $seoMetric = MetricDefinition::factory()->create(['category_key' => 'technical_seo', 'scoring_type' => 'boolean', 'max_score' => 20]);
        $contentMetricAvailable = MetricDefinition::factory()->create(['category_key' => 'content', 'scoring_type' => 'ratio', 'max_score' => 10]);
        $contentMetricUnavailable = MetricDefinition::factory()->create(['category_key' => 'content', 'scoring_type' => 'boolean', 'max_score' => 5]);

        MetricResult::factory()->create(['metric_definition_id' => $seoMetric->id, 'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => true], 'confidence' => 1.0]);
        MetricResult::factory()->create(['metric_definition_id' => $contentMetricAvailable->id, 'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => 0.8], 'confidence' => 0.9]);
        MetricResult::factory()->create(['metric_definition_id' => $contentMetricUnavailable->id, 'status' => MetricResultStatus::Unavailable]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $categories = collect([$techSeo, $content]);

        $calculator = new OverallScoreCalculator(
            new MetricScorer,
            new CategoryScoreCalculator(new CoverageCalculator),
            new CoverageCalculator,
            new \App\Services\Scoring\ConfidenceCalculator,
        );

        $score = $calculator->calculate($categories, $results);

        // configured_max = 20+15=35, available = 20(seo, all counted)+10(content available)=30
        $this->assertSame(35.0, $score->configuredMaxScore);
        $this->assertSame(30.0, $score->availableScore);
        // overall = 20(seo full) + 8(content: 0.8*10)=28
        $this->assertSame(28.0, $score->overallScore);
        $this->assertSame(28, $score->displayScore);
        $this->assertEqualsWithDelta(30 / 35 * 100, $score->coverageRate, 0.01);
        $this->assertSame(2, $score->metricSummary['success']);
        $this->assertSame(1, $score->metricSummary['unavailable']);
    }

    public function test_overall_score_calculator_separates_scored_from_informational_unavailable(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'technology', 'weight' => 10, 'display_order' => 1]);
        $scoredMetric = MetricDefinition::factory()->create(['category_key' => 'technology', 'scoring_type' => 'boolean', 'max_score' => 3]);
        $informationalMetric = MetricDefinition::factory()->create(['category_key' => 'technology', 'scoring_type' => 'not_scored', 'max_score' => 0]);

        // 技術検出ジョブが全滅した状況を模す: 採点対象1件・情報項目1件がどちらもError。
        MetricResult::factory()->create(['metric_definition_id' => $scoredMetric->id, 'status' => MetricResultStatus::Error]);
        MetricResult::factory()->create(['metric_definition_id' => $informationalMetric->id, 'status' => MetricResultStatus::Error]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $calculator = new OverallScoreCalculator(
            new MetricScorer,
            new CategoryScoreCalculator(new CoverageCalculator),
            new CoverageCalculator,
            new \App\Services\Scoring\ConfidenceCalculator,
        );

        $score = $calculator->calculate(collect([$category]), $results);

        $this->assertSame(2, $score->metricSummary['error']);
        $this->assertSame(1, $score->metricSummary['scored_unavailable']);
        $this->assertSame(1, $score->metricSummary['informational_unavailable']);
    }
}
