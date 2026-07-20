<?php

namespace App\Support\Scoring;

/**
 * MetricScorerの計算結果。「採点の分母に含めるか」と「含める場合の得点」を
 * 明確に分離する ―― unavailable/error/not_applicable/not_found(excludeポリシー)や
 * scoring_type=not_scoredの項目は分母からも除外され、0点として扱われない。
 */
final readonly class MetricScoreOutcome
{
    private function __construct(
        public bool $countsTowardScore,
        public ?float $score,
        public ?float $maxScore,
    ) {
    }

    public static function excluded(): self
    {
        return new self(false, null, null);
    }

    public static function scored(float $score, float $maxScore): self
    {
        return new self(true, round($score, 2), round($maxScore, 2));
    }
}
