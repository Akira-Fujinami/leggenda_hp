<?php

namespace App\Services\AiAnalysis\Data;

/**
 * AI入力向けの競合サイトとのスコア差。
 * scoreGapは「競合の総合スコア - 対象サイトの総合スコア」(正なら競合が上回っている)。
 */
readonly class AiCompetitorGap
{
    public function __construct(
        public string $competitorName,
        public float $scoreGap,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'competitor_name' => $this->competitorName,
            'score_gap' => $this->scoreGap,
        ];
    }
}
