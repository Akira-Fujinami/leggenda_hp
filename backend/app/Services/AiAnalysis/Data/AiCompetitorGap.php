<?php

namespace App\Services\AiAnalysis\Data;

/**
 * AI入力向けの競合サイトとのスコア差。
 * scoreGapは「競合の総合スコア - 対象サイトの総合スコア」(正なら競合が上回っている)。
 * websiteAnalysisIdは、AIがcompetitor_insightsで参照してよい正当なIDの集合として
 * バリデーションにも使う。
 */
readonly class AiCompetitorGap
{
    public function __construct(
        public int $websiteAnalysisId,
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
            'website_analysis_id' => $this->websiteAnalysisId,
            'competitor_name' => $this->competitorName,
            'score_gap' => $this->scoreGap,
        ];
    }
}
