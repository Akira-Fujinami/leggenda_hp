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

        return array_merge($points, $this->sparseGroupPoints($axes));
    }

    /**
     * レポート(Word/PDF)専用。points()と同じ入力・同じ判定材料から組み立てるが、
     * 0件の軸が複数ある場合は1行ずつではなく1行にまとめる(2026-08-10、
     * ユーザー指摘)。points()自体はJSON API(LeadAnalysisController経由で
     * 画面 frontend/src/features/lead/)と共有しているため、画面の箇条書き
     * 件数を変えないよう、この挙動はpoints()には入れず別メソッドに分離した。
     *
     * 0件の軸をまとめても情報は1件も失われない(全軸名を「・」で連結して
     * 同じzero_axisテンプレートに渡すだけ)。これにより、0件の軸が多い
     * サイト(実測: 味の素、6軸中4軸が0件で7行になっていた)でも
     * 最大5行(充足軸1行+0件まとめ1行+グループ差最大3行)に収まることが
     * 構造的に保証されるため、ReportViewModelBuilder側の件数上限
     * (capSummaryPoints、最大4件で5件目以降を無言で切り捨てていた)は
     * この変更に伴い廃止した。
     *
     * @param  list<array{key: string, group: string, name: string, matched_count: int, max_count: int}>  $axes
     * @return list<string>
     */
    public function pointsForReport(array $axes): array
    {
        if ($axes === []) {
            return [];
        }

        $points = [];

        $mostFilled = collect($axes)->sortByDesc('matched_count')->first();
        if ($mostFilled !== null && $mostFilled['matched_count'] > 0) {
            $points[] = sprintf((string) config('brand_wheel.comparison_summary_templates.most_filled_axis'), $mostFilled['name']);
        }

        $zeroAxisNames = array_values(array_map(
            fn (array $axis) => $axis['name'],
            array_filter($axes, fn (array $axis) => $axis['matched_count'] === 0),
        ));

        if ($zeroAxisNames !== []) {
            $points[] = sprintf((string) config('brand_wheel.comparison_summary_templates.zero_axis'), implode('・', $zeroAxisNames));
        }

        return array_merge($points, $this->sparseGroupPoints($axes));
    }

    /**
     * @param  list<array{group: string, matched_count: int}>  $axes
     * @return list<string>
     */
    private function sparseGroupPoints(array $axes): array
    {
        $groupTotals = collect($axes)->groupBy('group')->map(fn ($group) => $group->sum('matched_count'));
        $maxGroupTotal = (int) $groupTotals->max();
        $gapRatioMax = (float) config('brand_wheel.comparison_summary_thresholds.group_gap_ratio_max', 0.5);
        $groupLabels = (array) config('brand_wheel.group_labels', []);

        if ($maxGroupTotal === 0) {
            return [];
        }

        $points = [];
        foreach ($groupTotals as $groupKey => $total) {
            if ($total < $maxGroupTotal * $gapRatioMax) {
                $label = $groupLabels[$groupKey] ?? $groupKey;
                $points[] = sprintf((string) config('brand_wheel.comparison_summary_templates.sparse_group'), $label);
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
