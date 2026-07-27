<?php

namespace Tests\Unit\Services\Report;

use App\Models\Analysis;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Services\Report\CategoryAvailabilityClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryAvailabilityClassifierTest extends TestCase
{
    use RefreshDatabase;

    private function classifier(): CategoryAvailabilityClassifier
    {
        return new CategoryAvailabilityClassifier;
    }

    public function test_a_category_with_positive_max_available_score_needs_no_label(): void
    {
        $analysis = Analysis::factory()->create(['skip_lighthouse' => false]);
        $definitions = collect([MetricDefinition::factory()->make(['source_type' => 'lighthouse'])]);

        $result = $this->classifier()->classify(10.0, $definitions, collect(), $analysis);

        $this->assertNull($result);
    }

    public function test_an_all_lighthouse_category_is_not_measured_when_lighthouse_is_skipped(): void
    {
        $analysis = Analysis::factory()->create(['skip_lighthouse' => true]);
        $definitions = collect([
            MetricDefinition::factory()->make(['source_type' => 'lighthouse', 'key' => 'lighthouse_accessibility']),
        ]);

        $result = $this->classifier()->classify(0.0, $definitions, collect(), $analysis);

        $this->assertSame(CategoryAvailabilityClassifier::NOT_MEASURED, $result);
    }

    public function test_an_all_lighthouse_category_is_unavailable_not_not_measured_when_lighthouse_was_not_skipped(): void
    {
        // skip_lighthouse=falseなのに0点ということは、実際に測定を試みて
        // 失敗した(Analyzer障害等)ことを意味するため「評価不可」。
        $analysis = Analysis::factory()->create(['skip_lighthouse' => false]);
        $definitions = collect([
            MetricDefinition::factory()->make(['source_type' => 'lighthouse', 'key' => 'lighthouse_accessibility']),
        ]);

        $result = $this->classifier()->classify(0.0, $definitions, collect(), $analysis);

        $this->assertSame(CategoryAvailabilityClassifier::UNAVAILABLE, $result);
    }

    public function test_an_all_semrush_category_is_not_measured_when_semrush_is_not_configured(): void
    {
        $analysis = Analysis::factory()->create();
        $definition = MetricDefinition::factory()->create(['source_type' => 'semrush', 'key' => 'authority_score']);
        $result = MetricResult::factory()->unavailable()->create([
            'metric_definition_id' => $definition->id,
            'error_code' => 'SEMRUSH_NOT_CONFIGURED',
        ]);

        $classification = $this->classifier()->classify(
            0.0,
            collect([$definition]),
            collect([$definition->id => $result]),
            $analysis,
        );

        $this->assertSame(CategoryAvailabilityClassifier::NOT_MEASURED, $classification);
    }

    public function test_an_all_semrush_category_is_unavailable_when_the_failure_is_not_the_not_configured_error(): void
    {
        // Semrushは設定済みだが、API側のタイムアウト・認証失敗等サイト/外部要因で
        // 取得できなかった場合は「評価不可」(弊社都合ではない)。
        $analysis = Analysis::factory()->create();
        $definition = MetricDefinition::factory()->create(['source_type' => 'semrush', 'key' => 'authority_score']);
        $result = MetricResult::factory()->unavailable()->create([
            'metric_definition_id' => $definition->id,
            'error_code' => 'SEMRUSH_TIMEOUT',
        ]);

        $classification = $this->classifier()->classify(
            0.0,
            collect([$definition]),
            collect([$definition->id => $result]),
            $analysis,
        );

        $this->assertSame(CategoryAvailabilityClassifier::UNAVAILABLE, $classification);
    }

    public function test_a_mixed_category_is_unavailable_when_only_some_definitions_are_disabled_by_us(): void
    {
        // technologyカテゴリのような、lighthouse_best_practices(省略対象)と
        // 技術検出(常時有効)が混在するケース。技術検出側が実際に失敗しているなら
        // 「計測対象外」と偽ってはいけない。
        $analysis = Analysis::factory()->create(['skip_lighthouse' => true]);
        $lighthouseDefinition = MetricDefinition::factory()->make(['source_type' => 'lighthouse', 'key' => 'lighthouse_best_practices']);
        $technologyDefinition = MetricDefinition::factory()->create(['source_type' => 'technology_detection', 'key' => 'cms_detected']);
        $failedResult = MetricResult::factory()->unavailable()->create([
            'metric_definition_id' => $technologyDefinition->id,
            'error_code' => 'RENDER_FAILED',
        ]);

        $classification = $this->classifier()->classify(
            0.0,
            collect([$lighthouseDefinition, $technologyDefinition]),
            collect([$technologyDefinition->id => $failedResult]),
            $analysis,
        );

        $this->assertSame(CategoryAvailabilityClassifier::UNAVAILABLE, $classification);
    }

    public function test_a_category_with_no_definitions_needs_no_label(): void
    {
        $analysis = Analysis::factory()->create();

        $result = $this->classifier()->classify(0.0, collect(), collect(), $analysis);

        $this->assertNull($result);
    }
}
