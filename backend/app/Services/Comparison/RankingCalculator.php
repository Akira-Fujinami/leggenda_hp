<?php

namespace App\Services\Comparison;

use App\Support\Comparison\RankedSite;
use App\Support\Comparison\SiteScoreEntry;
use Illuminate\Support\Collection;

/**
 * 総合順位を算出する。同点時の優先順は仕様どおり固定:
 * 1. overall_score  2. coverage_rate  3. confidence_rate
 * 4. technical_seoカテゴリスコア  5. performanceカテゴリスコア
 * 6. Website.display_order
 */
class RankingCalculator
{
    /**
     * データ取得率がこの値未満の場合、順位に注意表示を付ける。
     */
    private const LOW_COVERAGE_THRESHOLD = 50.0;

    /**
     * @param  Collection<int, SiteScoreEntry>  $entries
     * @return Collection<int, RankedSite>
     */
    public function rank(Collection $entries): Collection
    {
        $sorted = $entries
            ->sort(function (SiteScoreEntry $a, SiteScoreEntry $b) {
                // 1〜5番目は降順(値が大きいほど上位) -> b <=> a。
                // 6番目(display_order)だけ昇順(値が小さいほど上位) -> a <=> b。
                return ($b->score->overallScore <=> $a->score->overallScore)
                    ?: ($b->score->coverageRate <=> $a->score->coverageRate)
                    ?: ($b->score->confidenceRate <=> $a->score->confidenceRate)
                    ?: ($b->categoryScore('technical_seo') <=> $a->categoryScore('technical_seo'))
                    ?: ($b->categoryScore('performance') <=> $a->categoryScore('performance'))
                    ?: ($a->websiteAnalysis->website->display_order <=> $b->websiteAnalysis->website->display_order);
            })
            ->values();

        $rank = 0;

        return $sorted->map(function (SiteScoreEntry $entry) use (&$rank) {
            $rank++;

            return new RankedSite(
                rank: $rank,
                entry: $entry,
                lowDataWarning: $entry->score->coverageRate < self::LOW_COVERAGE_THRESHOLD,
            );
        });
    }
}
