<?php

namespace App\Services\AiAnalysis\Data;

/**
 * AI入力向けのカテゴリスコアのスナップショット。
 * OverallScoreCalculator::calculate()の結果(CategoryScoreResult)から
 * AIに渡す最小限の情報だけを抜き出す。
 */
readonly class AiCategoryScoreSnapshot
{
    public function __construct(
        public string $key,
        public string $name,
        public float $score,
        public float $maxScore,
        public float $coverageRate,
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
            'score' => $this->score,
            'max_score' => $this->maxScore,
            'coverage_rate' => $this->coverageRate,
        ];
    }
}
