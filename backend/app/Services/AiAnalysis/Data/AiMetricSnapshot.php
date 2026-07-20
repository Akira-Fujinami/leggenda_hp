<?php

namespace App\Services\AiAnalysis\Data;

/**
 * AI入力向けの個別Metricのスナップショット。正規化済みのvalueのみを保持し、
 * raw_value(Lighthouse生JSON等)やevidenceの生データは一切含めない。
 */
readonly class AiMetricSnapshot
{
    public function __construct(
        public string $key,
        public string $name,
        public string $categoryKey,
        public bool|int|float|string|null $value,
        public ?string $unit,
        public float $confidence,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'category_key' => $this->categoryKey,
            'value' => $this->value,
            'unit' => $this->unit,
            'confidence' => $this->confidence,
        ];
    }
}
