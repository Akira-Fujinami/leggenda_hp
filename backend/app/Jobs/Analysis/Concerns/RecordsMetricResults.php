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
     * @param  ?string  $source  静的HTML解析('static')かレンダリング済みHTML
     *                           解析('rendered')かの区別。nullを渡す呼び出し元
     *                           (Lighthouse/技術検出等、source優先度の概念が
     *                           無いジョブ)は従来通り無条件に上書きする
     *                           (挙動変化なし)。非nullを渡す場合のみ、既存行の
     *                           sourceより優先度が低ければ書き込みをスキップし、
     *                           renderedの結果をstaticが後から巻き戻すことを防ぐ。
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
        ?string $source = null,
    ): void {
        $definition = MetricDefinition::query()->where('key', $key)->where('is_active', true)->first();

        if ($definition === null) {
            return;
        }

        if ($source !== null) {
            $existing = MetricResult::query()
                ->where('website_analysis_id', $websiteAnalysisId)
                ->where('metric_definition_id', $definition->id)
                ->first();

            if ($existing !== null && $this->metricSourceRank($source) < $this->metricSourceRank($existing->source)) {
                return;
            }
        }

        MetricResult::query()->updateOrCreate(
            ['website_analysis_id' => $websiteAnalysisId, 'metric_definition_id' => $definition->id],
            [
                'analysis_page_id' => $analysisPageId,
                'raw_value' => $rawValue,
                'normalized_value' => $normalizedValue !== null ? ['value' => $normalizedValue] : null,
                'status' => $status,
                'evidence' => $evidence,
                'source' => $source,
                'confidence' => $confidence,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'measured_at' => now(),
            ],
        );
    }

    /**
     * rendered > static > (未設定)。数値が大きいほど優先度が高い。
     */
    private function metricSourceRank(?string $source): int
    {
        return match ($source) {
            'rendered' => 2,
            'static' => 1,
            default => 0,
        };
    }
}
