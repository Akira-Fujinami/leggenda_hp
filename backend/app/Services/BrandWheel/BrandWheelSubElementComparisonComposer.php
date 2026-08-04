<?php

namespace App\Services\BrandWheel;

/**
 * 24下位要素の「自社×競合」canonical list。config('brand_wheel.axes')の
 * 並び順(軸の並び→軸内sub_elementsの並び)をそのまま項目順として使う ――
 * 表示順をコードに直書きしない(docs/lead-report-layout/README.md)。
 *
 * 「24項目の対比」「サイトで触れられていなかった項目」「改善提案」の3ページは
 * すべてこのリスト1つから機械的に導出する。AIが決めるのは
 * matched_sub_elements/evidence/key_message/impressionの4つだけで、
 * それ以外の判定(●/－、A/B/C分類、グループ差)の発生源をここに集約する
 * (2026-08-04の設計方針、docs/lead-report-layout/README.md「【2】設計の要」)。
 *
 * 入力はBrandWheelLeadResponseComposer::compose()の`axes`
 * (status!=='success'の場合は空配列 ―― その場合該当する側の*_matchedは
 * 常にfalseになる。このページ自体を出すかどうかは呼び出し側がstatusを見て
 * 判断する)。
 */
class BrandWheelSubElementComparisonComposer
{
    /**
     * @param  list<array{key: string, group: string, name: string, matched_sub_elements: list<array{key: string, name: string}>}>  $selfAxes
     * @param  list<array{key: string, group: string, name: string, matched_sub_elements: list<array{key: string, name: string}>}>  $competitorAxes
     * @return list<array{axis_key: string, axis_name: string, group: string, sub_key: string, sub_name: string, definition: string, self_matched: bool, competitor_matched: bool}>
     */
    public function compose(array $selfAxes, array $competitorAxes): array
    {
        $selfMatchedKeys = $this->matchedKeysByAxis($selfAxes);
        $competitorMatchedKeys = $this->matchedKeysByAxis($competitorAxes);

        $axesConfig = (array) config('brand_wheel.axes', []);

        $items = [];
        foreach ($axesConfig as $axisKey => $axisDefinition) {
            $subElements = (array) ($axisDefinition['sub_elements'] ?? []);
            $definitions = (array) ($axisDefinition['sub_element_definitions'] ?? []);

            foreach ($subElements as $subKey => $subName) {
                $items[] = [
                    'axis_key' => $axisKey,
                    'axis_name' => (string) ($axisDefinition['name_ja'] ?? $axisKey),
                    'group' => (string) ($axisDefinition['group'] ?? ''),
                    'sub_key' => $subKey,
                    'sub_name' => $subName,
                    'definition' => (string) ($definitions[$subKey] ?? ''),
                    'self_matched' => in_array($subKey, $selfMatchedKeys[$axisKey] ?? [], true),
                    'competitor_matched' => in_array($subKey, $competitorMatchedKeys[$axisKey] ?? [], true),
                ];
            }
        }

        return $items;
    }

    /**
     * 「サイトで触れられていなかった項目」ページの3分類。
     *   a: 比較サイトにあり、自社に無い
     *   b: 自社にあり、比較サイトに無い
     *   c: どちらにも無い
     * (共通=両方に該当、はこのページでは表示しないため返さない。
     * a+b+c+共通=24の検算はテスト側で行う。)
     *
     * @param  list<array{self_matched: bool, competitor_matched: bool}>  $comparisonItems  compose()の戻り値
     * @return array{a: list<array<string, mixed>>, b: list<array<string, mixed>>, c: list<array<string, mixed>>}
     */
    public function splitByGap(array $comparisonItems): array
    {
        return [
            'a' => array_values(array_filter($comparisonItems, fn (array $i) => $i['competitor_matched'] && ! $i['self_matched'])),
            'b' => array_values(array_filter($comparisonItems, fn (array $i) => $i['self_matched'] && ! $i['competitor_matched'])),
            'c' => array_values(array_filter($comparisonItems, fn (array $i) => ! $i['self_matched'] && ! $i['competitor_matched'])),
        ];
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
