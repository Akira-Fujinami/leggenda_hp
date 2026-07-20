<?php

namespace App\Jobs\Analysis\Concerns;

use App\Enums\MetricResultStatus;
use App\Models\MetricDefinition;
use App\Models\MetricResult;

/**
 * MetricDefinition.keyを指定してMetricResult行をupsertするための共通処理。
 *
 * 採点(score/max_score)はここでは行わない ―― Phase 3以降、採点は
 * MetricScorerによる読み取り時計算に一本化されており(MetricDefinition/
 * CategoryDefinitionの配点変更を過去のAnalysisにも反映できるようにするため)、
 * ここでは実測値をnormalized_value(標準化された値)として保存するだけに留める。
 */
trait RecordsMetricResults
{
    /**
     * @param  mixed  $normalizedValue  MetricScorerが解釈する標準化された値
     *                                  (bool/number)。status=Successのときのみ意味を持つ。
     * @param  array<string, mixed>|null  $rawValue
     * @param  array<string, mixed>|null  $evidence
     */
    private function recordMetric(
        int $websiteAnalysisId,
        string $key,
        MetricResultStatus $status,
        mixed $normalizedValue = null,
        ?array $rawValue = null,
        ?array $evidence = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?int $analysisPageId = null,
        float $confidence = 1.0,
    ): void {
        $definition = MetricDefinition::query()->where('key', $key)->where('is_active', true)->first();

        if ($definition === null) {
            return;
        }

        MetricResult::query()->updateOrCreate(
            ['website_analysis_id' => $websiteAnalysisId, 'metric_definition_id' => $definition->id],
            [
                'analysis_page_id' => $analysisPageId,
                'raw_value' => $rawValue,
                'normalized_value' => $normalizedValue !== null ? ['value' => $normalizedValue] : null,
                'status' => $status,
                'evidence' => $evidence,
                'confidence' => $confidence,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'measured_at' => now(),
            ],
        );
    }
}
