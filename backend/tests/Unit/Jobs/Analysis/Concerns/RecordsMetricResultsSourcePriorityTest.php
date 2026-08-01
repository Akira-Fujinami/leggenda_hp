<?php

namespace Tests\Unit\Jobs\Analysis\Concerns;

use App\Enums\MetricResultStatus;
use App\Jobs\Analysis\Concerns\RecordsMetricResults;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\WebsiteAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * RecordsMetricResults::recordMetric()のsource優先度ガード
 * (rendered > static > 未設定)を直接検証する。
 * static/rendered再解析の書き込み競合防止の中核部分。
 */
class RecordsMetricResultsSourcePriorityTest extends TestCase
{
    use RefreshDatabase;

    private function recorder(): object
    {
        return new class
        {
            use RecordsMetricResults;

            public function record(
                int $websiteAnalysisId,
                string $key,
                mixed $normalizedValue,
                ?string $source,
            ): void {
                $this->recordMetric(
                    $websiteAnalysisId, $key, MetricResultStatus::Success,
                    normalizedValue: $normalizedValue, source: $source,
                );
            }
        };
    }

    public function test_rendered_existing_blocks_a_later_static_write(): void
    {
        $definition = MetricDefinition::factory()->create(['key' => 'x_metric', 'scoring_type' => 'boolean']);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        $recorder = $this->recorder();
        $recorder->record($websiteAnalysis->id, 'x_metric', true, 'rendered');
        $recorder->record($websiteAnalysis->id, 'x_metric', false, 'static');

        $result = MetricResult::query()->where('website_analysis_id', $websiteAnalysis->id)
            ->where('metric_definition_id', $definition->id)->first();

        $this->assertSame('rendered', $result->source);
        $this->assertTrue($result->normalized_value['value'], 'staticによる巻き戻しがブロックされ、rendered時の値が保持される');
    }

    public function test_static_existing_allows_a_later_rendered_write(): void
    {
        $definition = MetricDefinition::factory()->create(['key' => 'x_metric', 'scoring_type' => 'boolean']);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        $recorder = $this->recorder();
        $recorder->record($websiteAnalysis->id, 'x_metric', false, 'static');
        $recorder->record($websiteAnalysis->id, 'x_metric', true, 'rendered');

        $result = MetricResult::query()->where('website_analysis_id', $websiteAnalysis->id)
            ->where('metric_definition_id', $definition->id)->first();

        $this->assertSame('rendered', $result->source);
        $this->assertTrue($result->normalized_value['value']);
    }

    public function test_no_existing_row_always_allows_the_write(): void
    {
        $definition = MetricDefinition::factory()->create(['key' => 'x_metric', 'scoring_type' => 'boolean']);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        $this->recorder()->record($websiteAnalysis->id, 'x_metric', true, 'static');

        $result = MetricResult::query()->where('website_analysis_id', $websiteAnalysis->id)
            ->where('metric_definition_id', $definition->id)->first();

        $this->assertNotNull($result);
        $this->assertSame('static', $result->source);
    }

    public function test_null_source_callers_always_overwrite_regardless_of_existing_source(): void
    {
        // Lighthouse/技術検出等、sourceの概念を持たない既存呼び出し元は
        // このガードの影響を受けない(挙動変化なし)。
        $definition = MetricDefinition::factory()->create(['key' => 'x_metric', 'scoring_type' => 'boolean']);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        $recorder = $this->recorder();
        $recorder->record($websiteAnalysis->id, 'x_metric', true, 'rendered');
        $recorder->record($websiteAnalysis->id, 'x_metric', false, null);

        $result = MetricResult::query()->where('website_analysis_id', $websiteAnalysis->id)
            ->where('metric_definition_id', $definition->id)->first();

        $this->assertNull($result->source);
        $this->assertFalse($result->normalized_value['value']);
    }

    public function test_a_key_with_no_active_definition_logs_an_error_and_still_skips_silently(): void
    {
        // #A-2: metric_definitionsに有効な定義が無いキーへの書き込みは、
        // 2026-07-20/2026-08-01の未シード事故の再発防止としてLog::errorに
        // 記録されるべきだが、スコアリングへの影響(スキップする挙動自体)は
        // 変えてはならない。
        Log::spy();

        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        $this->recorder()->record($websiteAnalysis->id, 'no_such_metric_key', true, 'static');

        $this->assertSame(0, MetricResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => $context['key'] === 'no_such_metric_key'
                && $context['website_analysis_id'] === $websiteAnalysis->id);
    }

    public function test_an_inactive_definition_is_treated_the_same_as_a_missing_one(): void
    {
        MetricDefinition::factory()->create(['key' => 'x_metric_inactive', 'scoring_type' => 'boolean', 'is_active' => false]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        Log::spy();

        $this->recorder()->record($websiteAnalysis->id, 'x_metric_inactive', true, 'static');

        $this->assertSame(0, MetricResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
        Log::shouldHaveReceived('error')->once();
    }
}
