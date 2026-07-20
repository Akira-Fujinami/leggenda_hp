<?php

namespace Tests\Unit\Scoring;

use App\Enums\MetricResultStatus;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Services\Scoring\MetricScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetricScorerTest extends TestCase
{
    use RefreshDatabase;

    private MetricScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new MetricScorer;
    }

    private function definition(array $overrides = []): MetricDefinition
    {
        return MetricDefinition::factory()->make(array_merge(['scoring_type' => 'boolean', 'max_score' => 10], $overrides));
    }

    public function test_success_status_is_scored_via_strategy(): void
    {
        $definition = $this->definition();
        $result = MetricResult::factory()->make(['status' => MetricResultStatus::Success, 'normalized_value' => ['value' => true]]);

        $outcome = $this->scorer->score($definition, $result);

        $this->assertTrue($outcome->countsTowardScore);
        $this->assertSame(10.0, $outcome->score);
        $this->assertSame(10.0, $outcome->maxScore);
    }

    public function test_unavailable_not_applicable_and_error_are_excluded(): void
    {
        $definition = $this->definition();

        foreach ([MetricResultStatus::Unavailable, MetricResultStatus::NotApplicable, MetricResultStatus::Error] as $status) {
            $result = MetricResult::factory()->make(['status' => $status]);
            $outcome = $this->scorer->score($definition, $result);

            $this->assertFalse($outcome->countsTowardScore, "{$status->value} should be excluded");
            $this->assertNull($outcome->score);
        }
    }

    public function test_not_found_with_zero_policy_scores_zero_but_counts(): void
    {
        $definition = $this->definition(['not_found_policy' => 'zero']);
        $result = MetricResult::factory()->make(['status' => MetricResultStatus::NotFound]);

        $outcome = $this->scorer->score($definition, $result);

        $this->assertTrue($outcome->countsTowardScore);
        $this->assertSame(0.0, $outcome->score);
        $this->assertSame(10.0, $outcome->maxScore);
    }

    public function test_not_found_with_exclude_policy_is_excluded(): void
    {
        $definition = $this->definition(['not_found_policy' => 'exclude']);
        $result = MetricResult::factory()->make(['status' => MetricResultStatus::NotFound]);

        $outcome = $this->scorer->score($definition, $result);

        $this->assertFalse($outcome->countsTowardScore);
    }

    public function test_not_found_with_partial_policy_uses_partial_rate(): void
    {
        $definition = $this->definition(['not_found_policy' => 'partial', 'not_found_partial_rate' => 0.4]);
        $result = MetricResult::factory()->make(['status' => MetricResultStatus::NotFound]);

        $outcome = $this->scorer->score($definition, $result);

        $this->assertTrue($outcome->countsTowardScore);
        $this->assertSame(4.0, $outcome->score);
    }

    public function test_not_scored_type_is_always_excluded_even_on_success(): void
    {
        $definition = $this->definition(['scoring_type' => 'not_scored']);
        $result = MetricResult::factory()->make(['status' => MetricResultStatus::Success, 'normalized_value' => ['value' => true]]);

        $outcome = $this->scorer->score($definition, $result);

        $this->assertFalse($outcome->countsTowardScore);
    }

    public function test_inactive_definition_is_excluded_regardless_of_status(): void
    {
        $definition = $this->definition(['is_active' => false]);
        $result = MetricResult::factory()->make(['status' => MetricResultStatus::Success, 'normalized_value' => ['value' => true]]);

        $outcome = $this->scorer->score($definition, $result);

        $this->assertFalse($outcome->countsTowardScore);
    }

    public function test_invalid_scoring_type_string_falls_back_to_not_scored_instead_of_crashing(): void
    {
        $definition = $this->definition(['scoring_type' => 'totally_bogus_type']);
        $result = MetricResult::factory()->make(['status' => MetricResultStatus::Success, 'normalized_value' => ['value' => true]]);

        $outcome = $this->scorer->score($definition, $result);

        $this->assertFalse($outcome->countsTowardScore);
    }

    public function test_invalid_not_found_policy_string_falls_back_to_zero_instead_of_crashing(): void
    {
        $definition = $this->definition(['not_found_policy' => 'totally_bogus_policy']);
        $result = MetricResult::factory()->make(['status' => MetricResultStatus::NotFound]);

        $outcome = $this->scorer->score($definition, $result);

        $this->assertTrue($outcome->countsTowardScore);
        $this->assertSame(0.0, $outcome->score);
    }
}
