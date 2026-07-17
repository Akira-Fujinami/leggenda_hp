<?php

namespace Tests\Unit\Analysis;

use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Services\Analysis\ScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoreCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private ScoreCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new ScoreCalculator;
    }

    public function test_it_scores_only_successful_metrics_and_excludes_others_from_denominator(): void
    {
        $success = MetricDefinition::factory()->create(['category' => 'technical_seo', 'max_score' => 8]);
        $failed = MetricDefinition::factory()->create(['category' => 'technical_seo', 'max_score' => 6]);
        $unavailable = MetricDefinition::factory()->create(['category' => 'content', 'max_score' => 5]);

        MetricResult::factory()->for($success, 'metricDefinition')->create(['score' => 8, 'max_score' => 8]);
        MetricResult::factory()->failed()->for($failed, 'metricDefinition')->create();
        MetricResult::factory()->unavailable()->for($unavailable, 'metricDefinition')->create();
        $results = MetricResult::query()->with('metricDefinition')->get();

        $result = $this->calculator->calculate($results, MetricDefinition::all());

        $this->assertSame(8.0, $result['total_score']);
        $this->assertSame(8.0, $result['max_available_score']);
        // 分母(全定義のmax_score合計)は 8+6+5=19、取得できたのは8のみ -> coverage 8/19
        $this->assertEqualsWithDelta(8 / 19, $result['coverage_rate'], 0.0001);
        $this->assertSame(1, $result['failed_metric_count']);
        $this->assertSame(1, $result['unavailable_metric_count']);
    }

    public function test_it_breaks_down_scores_per_category(): void
    {
        $techDef = MetricDefinition::factory()->create(['category' => 'technical_seo', 'max_score' => 10]);
        $contentDef = MetricDefinition::factory()->create(['category' => 'content', 'max_score' => 10]);

        MetricResult::factory()->for($techDef, 'metricDefinition')->create(['score' => 10, 'max_score' => 10]);
        MetricResult::factory()->for($contentDef, 'metricDefinition')->create(['score' => 4, 'max_score' => 10]);
        $results = MetricResult::query()->with('metricDefinition')->get();

        $result = $this->calculator->calculate($results, MetricDefinition::all());

        $this->assertSame(14.0, $result['total_score']);
        $this->assertSame(10.0, $result['categories']['technical_seo']['score']);
        $this->assertSame(4.0, $result['categories']['content']['score']);
        $this->assertSame(10.0, $result['categories']['technical_seo']['max_score']);
    }

    public function test_it_ignores_inactive_definitions_in_denominator(): void
    {
        $active = MetricDefinition::factory()->create(['category' => 'technical_seo', 'max_score' => 5, 'is_active' => true]);
        MetricDefinition::factory()->create(['category' => 'technical_seo', 'max_score' => 100, 'is_active' => false]);

        MetricResult::factory()->for($active, 'metricDefinition')->create(['score' => 5, 'max_score' => 5]);
        $results = MetricResult::query()->with('metricDefinition')->get();

        $result = $this->calculator->calculate($results, MetricDefinition::where('is_active', true)->get());

        $this->assertSame(1.0, $result['coverage_rate']);
    }

    public function test_it_returns_zero_scores_when_no_results_available(): void
    {
        MetricDefinition::factory()->create(['category' => 'technical_seo', 'max_score' => 10]);

        $result = $this->calculator->calculate(collect(), MetricDefinition::all());

        $this->assertSame(0.0, $result['total_score']);
        $this->assertSame(0.0, $result['max_available_score']);
        $this->assertSame(0.0, $result['coverage_rate']);
    }
}
