<?php

namespace App\Support\Scoring;

/**
 * MetricDefinition.thresholds (jsonb) を検証・解釈するValue Object。
 *
 * DBに不正な形式が入っていても例外を投げず、fromArray()がnullを返すことで
 * 呼び出し側(ThresholdMetricScorer)が安全にフォールバックできるようにする
 * ―― 1件の設定ミスが結果画面全体を500エラーにしないための防御。
 */
final readonly class ScoreThresholdSet
{
    /**
     * @param  list<ScoreThreshold>  $thresholds
     */
    private function __construct(private array $thresholds)
    {
    }

    public static function fromArray(mixed $raw): ?self
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $thresholds = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                return null;
            }

            if (! isset($item['min'], $item['max'], $item['score_rate'])) {
                return null;
            }

            if (! is_numeric($item['min']) || ! is_numeric($item['max']) || ! is_numeric($item['score_rate'])) {
                return null;
            }

            $thresholds[] = new ScoreThreshold(
                (float) $item['min'],
                (float) $item['max'],
                (float) $item['score_rate'],
            );
        }

        return new self($thresholds);
    }

    /**
     * 該当する区間のscore_rateを返す。どの区間にも一致しない場合は、
     * 値が全区間より下なら最小区間のレート、上なら最大区間のレートに
     * フォールバックする(0点断定を避ける)。
     */
    public function rateFor(float $value): float
    {
        foreach ($this->thresholds as $threshold) {
            if ($threshold->contains($value)) {
                return max(0.0, min(1.0, $threshold->scoreRate));
            }
        }

        $sorted = $this->thresholds;
        usort($sorted, fn (ScoreThreshold $a, ScoreThreshold $b) => $a->min <=> $b->min);

        if ($sorted === []) {
            return 0.0;
        }

        $lowest = $sorted[array_key_first($sorted)];
        $highest = $sorted[array_key_last($sorted)];

        if ($value < $lowest->min) {
            return max(0.0, min(1.0, $lowest->scoreRate));
        }

        return max(0.0, min(1.0, $highest->scoreRate));
    }
}
