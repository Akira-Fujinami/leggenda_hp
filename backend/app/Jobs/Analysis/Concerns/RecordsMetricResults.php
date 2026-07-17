<?php

namespace App\Jobs\Analysis\Concerns;

use App\Enums\MetricResultStatus;
use App\Models\MetricDefinition;
use App\Models\MetricResult;

/**
 * MetricDefinition.keyを指定してMetricResult行をupsertするための共通処理。
 * 配点(max_score)は必ずMetricDefinitionから取得し、Job側にハードコードしない。
 */
trait RecordsMetricResults
{
    /**
     * @param  array<string, mixed>|null  $rawValue
     * @param  array<string, mixed>|null  $evidence
     */
    /**
     * @param  float  $achievedRatio  0.0〜1.0。配点(max_score)に対する達成率。
     *                                真偽のみのメトリクスは1.0(満点)または0.0を渡す。
     * @param  array<string, mixed>|null  $rawValue
     * @param  array<string, mixed>|null  $evidence
     */
    private function recordMetric(
        int $websiteAnalysisId,
        string $key,
        MetricResultStatus $status,
        float $achievedRatio = 1.0,
        ?array $rawValue = null,
        ?array $evidence = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?int $analysisPageId = null,
    ): void {
        $definition = MetricDefinition::query()->where('key', $key)->where('is_active', true)->first();

        if ($definition === null) {
            return;
        }

        $maxScore = $status === MetricResultStatus::Success ? (float) $definition->max_score : null;
        $score = $status === MetricResultStatus::Success
            ? round(max(0.0, min(1.0, $achievedRatio)) * (float) $definition->max_score, 2)
            : null;

        MetricResult::query()->updateOrCreate(
            ['website_analysis_id' => $websiteAnalysisId, 'metric_definition_id' => $definition->id],
            [
                'analysis_page_id' => $analysisPageId,
                'raw_value' => $rawValue,
                'score' => $score,
                'max_score' => $maxScore,
                'status' => $status,
                'evidence' => $evidence,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'measured_at' => now(),
            ],
        );
    }
}
