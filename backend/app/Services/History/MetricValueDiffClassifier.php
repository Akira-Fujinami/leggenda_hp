<?php

namespace App\Services\History;

use App\Models\MetricDefinition;

/**
 * 過去分析との値の差分を「改善/悪化/変更」に分類する。
 * value_typeごとに比較方法を分け、文字列変更だけで改善・悪化を
 * 判断できないもの(title文字列・技術スタックの変更等)は
 * 「変更」として扱う(改善とは断定しない)。
 */
class MetricValueDiffClassifier
{
    private const NUMERIC_TYPES = ['number', 'percentage', 'score', 'duration', 'count'];

    public function classify(MetricDefinition $definition, mixed $previousValue, mixed $currentValue): string
    {
        if ($previousValue === $currentValue) {
            return 'unchanged';
        }

        if ($previousValue === null || $currentValue === null) {
            return 'changed';
        }

        if ($definition->value_type === 'boolean' || is_bool($previousValue) || is_bool($currentValue)) {
            return $this->classifyBoolean($definition, (bool) $previousValue, (bool) $currentValue);
        }

        if (in_array($definition->value_type, self::NUMERIC_TYPES, true) && is_numeric($previousValue) && is_numeric($currentValue)) {
            return $this->classifyNumeric($definition, (float) $previousValue, (float) $currentValue);
        }

        // string/list等、値の性質上「良し悪し」を機械的に断定できないものは変更のみ報告する。
        return 'changed';
    }

    private function classifyBoolean(MetricDefinition $definition, bool $previous, bool $current): string
    {
        if ($previous === $current) {
            return 'unchanged';
        }

        $becameTrue = $current === true;

        return $becameTrue === $definition->higher_is_better ? 'improved' : 'degraded';
    }

    private function classifyNumeric(MetricDefinition $definition, float $previous, float $current): string
    {
        if (abs($current - $previous) < 0.0001) {
            return 'unchanged';
        }

        $increased = $current > $previous;

        return $increased === $definition->higher_is_better ? 'improved' : 'degraded';
    }
}
