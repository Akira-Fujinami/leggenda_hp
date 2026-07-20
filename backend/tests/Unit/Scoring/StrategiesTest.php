<?php

namespace Tests\Unit\Scoring;

use App\Enums\MetricResultStatus;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Services\Scoring\Strategies\BooleanMetricScorer;
use App\Services\Scoring\Strategies\InverseLinearMetricScorer;
use App\Services\Scoring\Strategies\LighthouseMetricScorer;
use App\Services\Scoring\Strategies\LinearMetricScorer;
use App\Services\Scoring\Strategies\ManualMetricScorer;
use App\Services\Scoring\Strategies\NotScoredMetricScorer;
use App\Services\Scoring\Strategies\RangeMetricScorer;
use App\Services\Scoring\Strategies\RatioMetricScorer;
use App\Services\Scoring\Strategies\ThresholdMetricScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategiesTest extends TestCase
{
    use RefreshDatabase;

    private function definition(array $overrides = []): MetricDefinition
    {
        return MetricDefinition::factory()->make($overrides);
    }

    private function metricResult(mixed $value): MetricResult
    {
        return MetricResult::factory()->make(['normalized_value' => ['value' => $value], 'status' => MetricResultStatus::Success]);
    }

    public function test_boolean_scorer_true_is_full_ratio(): void
    {
        $scorer = new BooleanMetricScorer;
        $this->assertSame(1.0, $scorer->calculateRatio($this->definition(), $this->metricResult(true)));
        $this->assertSame(0.0, $scorer->calculateRatio($this->definition(), $this->metricResult(false)));
    }

    public function test_boolean_scorer_inverts_when_higher_is_better_false(): void
    {
        $scorer = new BooleanMetricScorer;
        $definition = $this->definition(['higher_is_better' => false]);
        $this->assertSame(0.0, $scorer->calculateRatio($definition, $this->metricResult(true)));
        $this->assertSame(1.0, $scorer->calculateRatio($definition, $this->metricResult(false)));
    }

    public function test_linear_scorer_interpolates_between_min_and_target(): void
    {
        $scorer = new LinearMetricScorer;
        $definition = $this->definition(['minimum_value' => 0, 'target_value' => 300]);

        $this->assertSame(0.0, $scorer->calculateRatio($definition, $this->metricResult(0)));
        $this->assertSame(0.5, $scorer->calculateRatio($definition, $this->metricResult(150)));
        $this->assertSame(1.0, $scorer->calculateRatio($definition, $this->metricResult(300)));
        // targetを超えても1.0でクランプされる
        $this->assertSame(1.0, $scorer->calculateRatio($definition, $this->metricResult(1000)));
    }

    public function test_linear_scorer_falls_back_to_zero_on_invalid_config(): void
    {
        $scorer = new LinearMetricScorer;
        // target <= min という不正な設定
        $definition = $this->definition(['minimum_value' => 100, 'target_value' => 50]);

        $this->assertSame(0.0, $scorer->calculateRatio($definition, $this->metricResult(75)));
    }

    public function test_inverse_linear_scorer_rewards_lower_values(): void
    {
        $scorer = new InverseLinearMetricScorer;
        $definition = $this->definition(['target_value' => 2500, 'maximum_value' => 4500]);

        $this->assertSame(1.0, $scorer->calculateRatio($definition, $this->metricResult(2500)));
        $this->assertSame(0.5, $scorer->calculateRatio($definition, $this->metricResult(3500)));
        $this->assertSame(0.0, $scorer->calculateRatio($definition, $this->metricResult(4500)));
        $this->assertSame(0.0, $scorer->calculateRatio($definition, $this->metricResult(9000)));
    }

    public function test_range_scorer_is_boolean_in_range(): void
    {
        $scorer = new RangeMetricScorer;
        $definition = $this->definition(['minimum_value' => 10, 'maximum_value' => 65]);

        $this->assertSame(1.0, $scorer->calculateRatio($definition, $this->metricResult(40)));
        $this->assertSame(0.0, $scorer->calculateRatio($definition, $this->metricResult(5)));
        $this->assertSame(0.0, $scorer->calculateRatio($definition, $this->metricResult(100)));
    }

    public function test_threshold_scorer_uses_matching_bracket(): void
    {
        $scorer = new ThresholdMetricScorer;
        $definition = $this->definition(['thresholds' => [
            ['min' => 0, 'max' => 49, 'score_rate' => 0],
            ['min' => 50, 'max' => 89, 'score_rate' => 0.6],
            ['min' => 90, 'max' => 100, 'score_rate' => 1],
        ]]);

        $this->assertSame(0.0, $scorer->calculateRatio($definition, $this->metricResult(20)));
        $this->assertSame(0.6, $scorer->calculateRatio($definition, $this->metricResult(70)));
        $this->assertSame(1.0, $scorer->calculateRatio($definition, $this->metricResult(95)));
    }

    public function test_threshold_scorer_falls_back_to_zero_on_malformed_thresholds(): void
    {
        $scorer = new ThresholdMetricScorer;
        $definition = $this->definition(['thresholds' => ['not' => 'valid']]);

        $this->assertSame(0.0, $scorer->calculateRatio($definition, $this->metricResult(50)));
    }

    public function test_ratio_scorer_clamps_to_zero_one(): void
    {
        $scorer = new RatioMetricScorer;
        $definition = $this->definition();

        $this->assertSame(0.8, $scorer->calculateRatio($definition, $this->metricResult(0.8)));
        $this->assertSame(1.0, $scorer->calculateRatio($definition, $this->metricResult(1.5)));
        $this->assertSame(0.0, $scorer->calculateRatio($definition, $this->metricResult(-0.5)));
    }

    public function test_lighthouse_scorer_divides_by_100(): void
    {
        $scorer = new LighthouseMetricScorer;
        $definition = $this->definition();

        $this->assertSame(0.85, $scorer->calculateRatio($definition, $this->metricResult(85)));
    }

    public function test_manual_scorer_passes_through_ratio(): void
    {
        $scorer = new ManualMetricScorer;
        $definition = $this->definition();

        $this->assertSame(0.5, $scorer->calculateRatio($definition, $this->metricResult(0.5)));
    }

    public function test_not_scored_scorer_always_returns_zero(): void
    {
        $scorer = new NotScoredMetricScorer;
        $definition = $this->definition();

        $this->assertSame(0.0, $scorer->calculateRatio($definition, $this->metricResult(999)));
    }

    public function test_missing_normalized_value_defensively_returns_zero(): void
    {
        $definition = $this->definition(['minimum_value' => 0, 'target_value' => 100]);
        $result = MetricResult::factory()->make(['normalized_value' => null, 'status' => MetricResultStatus::Success]);

        $this->assertSame(0.0, (new LinearMetricScorer)->calculateRatio($definition, $result));
        $this->assertSame(0.0, (new RatioMetricScorer)->calculateRatio($definition, $result));
        $this->assertSame(0.0, (new ThresholdMetricScorer)->calculateRatio($definition, $result));
    }
}
