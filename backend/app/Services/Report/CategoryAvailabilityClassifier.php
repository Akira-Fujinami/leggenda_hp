<?php

namespace App\Services\Report;

use App\Models\Analysis;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use Illuminate\Support\Collection;

/**
 * カテゴリのmax_available_score<=0(既存画面の「評価不可」表示条件、
 * category-availability.tsのisCategoryUnavailableと同じ考え方)を、
 * さらに2つの理由に区別する ―― 営業上、この違いは重要(修正4)。
 *
 * - 計測対象外: 弊社側の都合(Semrush未設定・リード分析でのLighthouse省略)で
 *   今回そもそも測定していない。
 * - 評価不可: 測定を試みたが、サイト側の事情(クロール拒否・タイムアウト・
 *   レンダリング失敗等)で取得できなかった。
 *
 * 判定は、そのカテゴリに属する全MetricDefinitionが「弊社都合で無効化された
 * 情報源」に属する場合のみ「計測対象外」とし、1つでも実際に測定を試みて
 * 失敗したものが含まれる場合は「評価不可」とする(実際に問題が起きている
 * のに「今回は測定していません」と偽ることを防ぐため)。
 */
class CategoryAvailabilityClassifier
{
    private const SEMRUSH_NOT_CONFIGURED_ERROR_CODE = 'SEMRUSH_NOT_CONFIGURED';

    public const NOT_MEASURED = 'not_measured';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  Collection<int, MetricDefinition>  $categoryDefinitions  このカテゴリに属するis_active=trueの定義
     * @param  Collection<int, MetricResult>  $resultsByDefinitionId  metric_definition_id => MetricResult(存在する分のみ)
     * @return ?string max_available_scoreが0を超える場合はnull(この判定自体が不要)
     */
    public function classify(
        float $maxAvailableScore,
        Collection $categoryDefinitions,
        Collection $resultsByDefinitionId,
        Analysis $analysis,
    ): ?string {
        if ($maxAvailableScore > 0 || $categoryDefinitions->isEmpty()) {
            return null;
        }

        $allDisabledByUs = $categoryDefinitions->every(
            fn (MetricDefinition $definition) => $this->isDisabledByUs($definition, $resultsByDefinitionId->get($definition->id), $analysis)
        );

        return $allDisabledByUs ? self::NOT_MEASURED : self::UNAVAILABLE;
    }

    private function isDisabledByUs(MetricDefinition $definition, ?MetricResult $result, Analysis $analysis): bool
    {
        if ($definition->source_type === 'lighthouse') {
            return $analysis->skip_lighthouse === true;
        }

        if ($definition->source_type === 'semrush') {
            return $result !== null && $result->error_code === self::SEMRUSH_NOT_CONFIGURED_ERROR_CODE;
        }

        return false;
    }
}
