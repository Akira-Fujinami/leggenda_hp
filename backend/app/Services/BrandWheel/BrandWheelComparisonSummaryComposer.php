<?php

namespace App\Services\BrandWheel;

/**
 * リード向け画面【自社ページ】【他社ページ】【ワンポイント】の組み立て。
 * いずれもAIには一切書かせず、matched_count/max_count/groupの件数から
 * 機械的に導出する(2026-08-03のユーザー指摘: AIに書かせると実行のたびに
 * 変わり、検証もできない)。
 *
 * 入力はBrandWheelLeadResponseComposer::compose()の`axes`配列
 * (status!=='success'の場合は空配列)。
 */
class BrandWheelComparisonSummaryComposer
{
    /**
     * @param  list<array{key: string, group: string, name: string, matched_count: int, max_count: int}>  $axes
     * @return list<string>
     */
    public function points(array $axes): array
    {
        if ($axes === []) {
            return [];
        }

        $points = [];

        $mostFilled = collect($axes)->sortByDesc('matched_count')->first();
        if ($mostFilled !== null && $mostFilled['matched_count'] > 0) {
            $points[] = sprintf((string) config('brand_wheel.comparison_summary_templates.most_filled_axis'), $mostFilled['name']);
        }

        foreach ($axes as $axis) {
            if ($axis['matched_count'] === 0) {
                $points[] = sprintf((string) config('brand_wheel.comparison_summary_templates.zero_axis'), $axis['name']);
            }
        }

        $groupTotals = collect($axes)->groupBy('group')->map(fn ($group) => $group->sum('matched_count'));
        $maxGroupTotal = (int) $groupTotals->max();
        $gapRatioMax = (float) config('brand_wheel.comparison_summary_thresholds.group_gap_ratio_max', 0.5);
        $groupLabels = (array) config('brand_wheel.group_labels', []);

        if ($maxGroupTotal > 0) {
            foreach ($groupTotals as $groupKey => $total) {
                if ($total < $maxGroupTotal * $gapRatioMax) {
                    $label = $groupLabels[$groupKey] ?? $groupKey;
                    $points[] = sprintf((string) config('brand_wheel.comparison_summary_templates.sparse_group'), $label);
                }
            }
        }

        return $points;
    }

    /**
     * 【ワンポイント】。自社サイトの6軸のmatched_countのみを対象に判定する
     * (競合の状態では分岐させない ―― ワンポイントはリードへの助言のため、
     * 2026-08-03のユーザー指摘)。$selfAxesが空(status!=='success')の場合は
     * 判定不能としてnullを返す。
     *
     * @param  list<array{key: string, group: string, name: string, matched_count: int, max_count: int}>  $selfAxes
     * @return array{key: string, text: string}|null
     */
    public function onePoint(array $selfAxes): ?array
    {
        if ($selfAxes === []) {
            return null;
        }

        $thresholds = (array) config('brand_wheel.one_point_thresholds', []);
        $zeroAxesMinCount = (int) ($thresholds['zero_axes_min_count'] ?? 2);
        $avgRatioMax = (float) ($thresholds['low_uniform_avg_ratio_max'] ?? 0.5);
        $spreadMax = (float) ($thresholds['low_uniform_spread_max'] ?? 0.25);

        $zeroCount = count(array_filter($selfAxes, fn (array $axis) => $axis['matched_count'] === 0));

        if ($zeroCount >= $zeroAxesMinCount) {
            return ['key' => 'insufficient_content', 'text' => (string) config('brand_wheel.one_point_messages.insufficient_content')];
        }

        $ratios = array_map(
            fn (array $axis) => $axis['max_count'] > 0 ? $axis['matched_count'] / $axis['max_count'] : 0.0,
            $selfAxes,
        );
        $average = array_sum($ratios) / count($ratios);
        $spread = max($ratios) - min($ratios);

        if ($average <= $avgRatioMax && $spread <= $spreadMax) {
            return ['key' => 'uniform_low', 'text' => (string) config('brand_wheel.one_point_messages.uniform_low')];
        }

        return ['key' => 'well_covered', 'text' => (string) config('brand_wheel.one_point_messages.well_covered')];
    }
}
