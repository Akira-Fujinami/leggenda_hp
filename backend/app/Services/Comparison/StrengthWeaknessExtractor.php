<?php

namespace App\Services\Comparison;

use App\Enums\RecommendationPriority;
use Illuminate\Support\Collection;

/**
 * ルールベースで強み・弱みを抽出する。
 * 未取得(status不明・null)の項目は「弱み」と断定しない
 * ―― 実際に問題が検出された項目のみを弱みとして扱う。
 */
class StrengthWeaknessExtractor
{
    private const HIGH_CATEGORY_RATIO = 0.8;

    private const LOW_CATEGORY_RATIO = 0.5;

    private const MIN_CATEGORY_COVERAGE = 50.0;

    private const MIN_CONFIDENCE = 0.6;

    private const RELATIVE_DIFF_THRESHOLD = 0.1; // 競合平均比±10%を「明確な差」とみなす

    /**
     * @param  list<array<string, mixed>>  $categoryComparisons  ComparisonCalculator::compareCategories()の出力
     * @param  list<array<string, mixed>>  $metricComparisons  ComparisonCalculator::compareMetrics()の出力
     * @param  Collection<int, \App\Models\Recommendation>  $recommendationsForSite
     * @return array{strengths: list<array<string, mixed>>, weaknesses: list<array<string, mixed>>}
     */
    public function extract(int $websiteAnalysisId, array $categoryComparisons, array $metricComparisons, Collection $recommendationsForSite): array
    {
        $strengths = [];
        $weaknesses = [];

        foreach ($categoryComparisons as $category) {
            $site = collect($category['sites'])->firstWhere('website_analysis_id', $websiteAnalysisId);

            if ($site === null || $category['configured_max_score'] <= 0) {
                continue;
            }

            if ($site['coverage_rate'] < self::MIN_CATEGORY_COVERAGE) {
                continue;
            }

            $ratio = $site['score'] / $category['configured_max_score'];

            if ($ratio >= self::HIGH_CATEGORY_RATIO) {
                $strengths[] = [
                    'type' => 'category',
                    'category_key' => $category['key'],
                    'label' => "{$category['name']}のスコアが高水準です",
                ];
            } elseif ($ratio <= self::LOW_CATEGORY_RATIO) {
                $weaknesses[] = [
                    'type' => 'category',
                    'category_key' => $category['key'],
                    'label' => "{$category['name']}のスコアが低水準です",
                ];
            }
        }

        foreach ($metricComparisons as $metric) {
            $site = collect($metric['sites'])->firstWhere('website_analysis_id', $websiteAnalysisId);

            if ($site === null || $site['status'] !== 'success') {
                continue; // 未取得・エラーの項目は強み・弱み判定の対象にしない
            }

            if (($site['confidence'] ?? 1.0) < self::MIN_CONFIDENCE) {
                continue;
            }

            $others = collect($metric['sites'])->reject(fn ($s) => $s['website_analysis_id'] === $websiteAnalysisId);
            $comparison = $this->compareAgainstCompetitors($metric, $site, $others);

            if ($comparison === 'better') {
                $strengths[] = ['type' => 'metric', 'metric_key' => $metric['key'], 'label' => "{$metric['name']}が競合平均を上回っています"];
            } elseif ($comparison === 'worse') {
                $weaknesses[] = ['type' => 'metric', 'metric_key' => $metric['key'], 'label' => "{$metric['name']}が競合平均を下回っています"];
            }
        }

        foreach ($recommendationsForSite as $recommendation) {
            if (in_array($recommendation->priority, [RecommendationPriority::Critical, RecommendationPriority::High], true)) {
                $weaknesses[] = [
                    'type' => 'recommendation',
                    'metric_key' => null,
                    'label' => $recommendation->title,
                    'priority' => $recommendation->priority->value,
                ];
            }
        }

        return ['strengths' => $strengths, 'weaknesses' => $weaknesses];
    }

    /**
     * @param  array<string, mixed>  $metric
     * @param  array<string, mixed>  $site
     * @param  Collection<int, array<string, mixed>>  $others
     */
    private function compareAgainstCompetitors(array $metric, array $site, Collection $others): ?string
    {
        $successfulOthers = $others->filter(fn ($o) => $o['status'] === 'success' && $o['value'] !== null);

        if ($successfulOthers->isEmpty() || $site['value'] === null) {
            return null;
        }

        $higherIsBetter = $metric['higher_is_better'];

        if (is_bool($site['value'])) {
            $trueCount = $successfulOthers->filter(fn ($o) => $o['value'] === true)->count();
            $allTrue = $trueCount === $successfulOthers->count();
            $noneTrue = $trueCount === 0;

            if ($site['value'] === true && $noneTrue) {
                return $higherIsBetter ? 'better' : 'worse';
            }

            if ($site['value'] === false && $allTrue) {
                return $higherIsBetter ? 'worse' : 'better';
            }

            return null;
        }

        if (! is_numeric($site['value'])) {
            return null;
        }

        $numericOthers = $successfulOthers->filter(fn ($o) => is_numeric($o['value']))->map(fn ($o) => (float) $o['value']);

        if ($numericOthers->isEmpty()) {
            return null;
        }

        $average = $numericOthers->avg();

        if ($average == 0.0) {
            return null;
        }

        $relativeDiff = ((float) $site['value'] - $average) / abs($average);

        if (abs($relativeDiff) < self::RELATIVE_DIFF_THRESHOLD) {
            return null;
        }

        $isHigherThanAverage = $relativeDiff > 0;

        if ($higherIsBetter) {
            return $isHigherThanAverage ? 'better' : 'worse';
        }

        return $isHigherThanAverage ? 'worse' : 'better';
    }
}
