<?php

namespace App\Services\BrandWheel;

/**
 * 依頼AC-1(2026-08-27): 管理者向け多社比較レポート(自社1×競合N社、N=3〜5)の
 * 中核ロジック。既存のBrandWheelSubElementComparisonComposer(自社×競合1社
 * 専用、リード向けレポートで使用)とは完全に別クラスとする ―― 既存クラスは
 * 依頼AC禁止事項により無改修のため、入出力の形が異なる(competitor_matched:
 * boolではなくcompetitor_matched_flags: list<bool>を持つ)このロジックを
 * 追加するには新クラスが必要。
 *
 * config('brand_wheel.axes')の並び順(軸の並び→軸内sub_elementsの並び)を
 * そのまま項目順として使う点、24項目のcanonical listをここに集約する設計は
 * 既存クラスと同じ(docs/lead-report-layout/README.md「【2】設計の要」)。
 */
class BrandWheelMultiSiteComparisonComposer
{
    /**
     * @param  list<array{key: string, group: string, name: string, matched_sub_elements: list<array{key: string, name: string}>, label_only_sub_elements: list<array{key: string, name: string}>}>  $selfAxes
     * @param  list<list<array{key: string, group: string, name: string, matched_sub_elements: list<array{key: string, name: string}>, label_only_sub_elements: list<array{key: string, name: string}>}>>  $competitorAxesList  display_order順(自社=対象外、競合のみ)であることが前提。代表競合の選定(representativeCompetitorIndex())がこの前提に依存する。
     * @return list<array{axis_key: string, axis_name: string, group: string, sub_key: string, sub_name: string, definition: string, recommendation: string, self_matched: bool, competitor_matched_flags: list<bool>, competitor_matched_count: int, is_majority: bool}>
     */
    public function compose(array $selfAxes, array $competitorAxesList): array
    {
        $selfMatchedKeys = $this->matchedKeysByAxis($selfAxes);
        $competitorMatchedKeysList = array_map(
            fn (array $axes) => $this->matchedKeysByAxis($axes),
            $competitorAxesList,
        );

        $majorityThreshold = $this->majorityThreshold(count($competitorAxesList));

        $axesConfig = (array) config('brand_wheel.axes', []);

        $items = [];
        foreach ($axesConfig as $axisKey => $axisDefinition) {
            $subElements = (array) ($axisDefinition['sub_elements'] ?? []);
            $definitions = (array) ($axisDefinition['sub_element_definitions'] ?? []);
            $recommendations = (array) ($axisDefinition['sub_element_recommendations'] ?? []);

            foreach ($subElements as $subKey => $subName) {
                $selfMatched = in_array($subKey, $selfMatchedKeys[$axisKey] ?? [], true);

                $competitorMatchedFlags = array_values(array_map(
                    fn (array $matchedKeysByAxis) => in_array($subKey, $matchedKeysByAxis[$axisKey] ?? [], true),
                    $competitorMatchedKeysList,
                ));
                $competitorMatchedCount = count(array_filter($competitorMatchedFlags));

                $items[] = [
                    'axis_key' => $axisKey,
                    'axis_name' => (string) ($axisDefinition['name_ja'] ?? $axisKey),
                    'group' => (string) ($axisDefinition['group'] ?? ''),
                    'sub_key' => $subKey,
                    'sub_name' => $subName,
                    'definition' => (string) ($definitions[$subKey] ?? ''),
                    'recommendation' => (string) ($recommendations[$subKey] ?? ''),
                    'self_matched' => $selfMatched,
                    'competitor_matched_flags' => $competitorMatchedFlags,
                    'competitor_matched_count' => $competitorMatchedCount,
                    'is_majority' => $competitorMatchedCount >= $majorityThreshold,
                ];
            }
        }

        return $items;
    }

    /**
     * 過半数の定義(依頼者指定): floor(N/2)+1。intdiv($n,2)+1はPHPの整数除算
     * (切り捨て)そのままfloorと等価なため、この式でN=3→2、N=4→3、N=5→3を
     * 満たす(N=4が境界値 ―― 過半数は「半分ちょうど」では成立しない)。
     */
    public function majorityThreshold(int $competitorCount): int
    {
        return intdiv($competitorCount, 2) + 1;
    }

    /**
     * ①自社に足りない項目: 自社が非該当、かつ競合の過半数が該当。
     *
     * @param  list<array{self_matched: bool, is_majority: bool, competitor_matched_count: int}>  $items  compose()の戻り値
     */
    public function extractMissingFromSelf(array $items): array
    {
        $filtered = array_values(array_filter(
            $items,
            fn (array $item) => ! $item['self_matched'] && $item['is_majority'],
        ));

        return $this->sortByCompetitorCountDescending($filtered);
    }

    /**
     * ②自社の強み: 自社が該当、かつ競合の過半数が非該当。
     *
     * @param  list<array{self_matched: bool, is_majority: bool, competitor_matched_count: int}>  $items  compose()の戻り値
     */
    public function extractSelfStrengths(array $items): array
    {
        $filtered = array_values(array_filter(
            $items,
            fn (array $item) => $item['self_matched'] && ! $item['is_majority'],
        ));

        return $this->sortByCompetitorCountDescending($filtered);
    }

    /**
     * 該当する競合のうちdisplay_orderが最も早い1社(依頼AC-3、依頼者提案の
     * 決定的ルール)。$competitorMatchedFlagsは呼び出し元(compose()の
     * $competitorAxesList)がdisplay_order順であることが前提のため、
     * 「最初にtrueとなった添字」を返すだけで成立する。該当する競合が
     * 存在しない場合はnull。
     */
    public function representativeCompetitorIndex(array $competitorMatchedFlags): ?int
    {
        foreach ($competitorMatchedFlags as $index => $matched) {
            if ($matched) {
                return $index;
            }
        }

        return null;
    }

    /**
     * 該当社数の降順。同数の場合、config('brand_wheel.sub_element_definitions')
     * の並び順(=$itemsに渡された時点での並び)をそのまま保つ ――
     * PHP8+のusort()は安定ソートが保証されているため、タイブレークの
     * 比較キーを別途持たせなくても「同じ入力は常に同じ順序」を満たす
     * (依頼AC-1、テストで固定する)。
     *
     * @param  list<array{competitor_matched_count: int}>  $items
     */
    private function sortByCompetitorCountDescending(array $items): array
    {
        usort($items, fn (array $a, array $b) => $b['competitor_matched_count'] <=> $a['competitor_matched_count']);

        return $items;
    }

    /**
     * @param  list<array{key: string, matched_sub_elements: list<array{key: string}>}>  $axes
     * @return array<string, list<string>>
     */
    private function matchedKeysByAxis(array $axes): array
    {
        $result = [];
        foreach ($axes as $axis) {
            $result[$axis['key']] = array_column((array) ($axis['matched_sub_elements'] ?? []), 'key');
        }

        return $result;
    }
}
