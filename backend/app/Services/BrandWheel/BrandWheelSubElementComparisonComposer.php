<?php

namespace App\Services\BrandWheel;

/**
 * 24下位要素の「自社×競合」canonical list。config('brand_wheel.axes')の
 * 並び順(軸の並び→軸内sub_elementsの並び)をそのまま項目順として使う ――
 * 表示順をコードに直書きしない(docs/lead-report-layout/README.md)。
 *
 * 「○△－対比表」「改善提案」の2ページはすべてこのリスト1つから機械的に
 * 導出する。AIが決めるのはmatched_sub_elements/discarded_sub_elements/
 * evidence/key_message/impressionだけで、それ以外の判定(○△－、グループ差)
 * の発生源をここに集約する(2026-08-04の設計方針、
 * docs/lead-report-layout/README.md「【2】設計の要」)。
 *
 * 入力はBrandWheelLeadResponseComposer::compose()の`axes`
 * (status!=='success'の場合は空配列 ―― その場合該当する側は常にstate='none'
 * になる。このページ自体を出すかどうかは呼び出し側がstatusを見て判断する)。
 *
 * 2026-08-08: ○△－の3値化(現行の●／－の2値から変更)。判定は必ずこの
 * クラス(プログラム側)で行い、AIに3段階を判定させない ―― 「AIの引用を
 * 原文照合で検証する」仕組みの外側に検証できない判断を入れないため
 * (ユーザー指摘)。self_matched/competitor_matched(○のみtrue)は
 * BrandWheelImprovementFocusComposerの選定ロジックが引き続き参照するため
 * 意味・値とも変更しない(△はfalseのまま=未該当扱い、選定ロジック自体は
 * 無改修)。self_state/competitor_state('matched'|'label_only'|'none')を
 * 新たに追加し、○△－の表示はこちらを唯一の情報源とする。
 */
class BrandWheelSubElementComparisonComposer
{
    /**
     * @param  list<array{key: string, group: string, name: string, matched_sub_elements: list<array{key: string, name: string}>, label_only_sub_elements: list<array{key: string, name: string}>}>  $selfAxes
     * @param  list<array{key: string, group: string, name: string, matched_sub_elements: list<array{key: string, name: string}>, label_only_sub_elements: list<array{key: string, name: string}>}>  $competitorAxes
     * @return list<array{axis_key: string, axis_name: string, group: string, sub_key: string, sub_name: string, definition: string, recommendation: string, self_matched: bool, competitor_matched: bool, self_state: string, competitor_state: string}>
     */
    public function compose(array $selfAxes, array $competitorAxes): array
    {
        $selfMatchedKeys = $this->matchedKeysByAxis($selfAxes);
        $competitorMatchedKeys = $this->matchedKeysByAxis($competitorAxes);
        $selfLabelOnlyKeys = $this->labelOnlyKeysByAxis($selfAxes);
        $competitorLabelOnlyKeys = $this->labelOnlyKeysByAxis($competitorAxes);

        $axesConfig = (array) config('brand_wheel.axes', []);

        $items = [];
        foreach ($axesConfig as $axisKey => $axisDefinition) {
            $subElements = (array) ($axisDefinition['sub_elements'] ?? []);
            $definitions = (array) ($axisDefinition['sub_element_definitions'] ?? []);
            // 2026-08-25追加: 改善提案カード表示専用の行動文
            // (config('brand_wheel.axes.*.sub_element_recommendations'))。
            // 判定用のsub_element_definitionsとは別物 ―― AIプロンプトには
            // 使わない(BrandWheelImprovementSuggestionInputFactory/
            // OpenAiBrandWheelAnalysisProvider参照)。
            $recommendations = (array) ($axisDefinition['sub_element_recommendations'] ?? []);

            foreach ($subElements as $subKey => $subName) {
                $selfMatched = in_array($subKey, $selfMatchedKeys[$axisKey] ?? [], true);
                $competitorMatched = in_array($subKey, $competitorMatchedKeys[$axisKey] ?? [], true);

                $items[] = [
                    'axis_key' => $axisKey,
                    'axis_name' => (string) ($axisDefinition['name_ja'] ?? $axisKey),
                    'group' => (string) ($axisDefinition['group'] ?? ''),
                    'sub_key' => $subKey,
                    'sub_name' => $subName,
                    'definition' => (string) ($definitions[$subKey] ?? ''),
                    'recommendation' => (string) ($recommendations[$subKey] ?? ''),
                    'self_matched' => $selfMatched,
                    'competitor_matched' => $competitorMatched,
                    'self_state' => $this->state($selfMatched, in_array($subKey, $selfLabelOnlyKeys[$axisKey] ?? [], true)),
                    'competitor_state' => $this->state($competitorMatched, in_array($subKey, $competitorLabelOnlyKeys[$axisKey] ?? [], true)),
                ];
            }
        }

        return $items;
    }

    /**
     * 「○△－の対比表」ページ冒頭の比較サマリー用に、3グループ
     * (company_appeal/company_distance/job_appeal)ごとのself/competitor
     * matched件数と優劣判定をまとめる(2026-08-17追加)。判定は
     * config('brand_wheel.group_advantage_diff_min')(既定2)以上の差が
     * あれば優位/劣位、未満なら'even'とする ―― 完全にPHPの決定的ロジックで、
     * AIには一切判定させない(既存の○△－判定と同じ設計方針)。
     *
     * @param  list<array{group: string, self_matched: bool, competitor_matched: bool}>  $comparisonItems  compose()の戻り値
     * @return list<array{group: string, label: string, self_count: int, competitor_count: int, max_count: int, verdict: string}>
     */
    public function groupTotals(array $comparisonItems): array
    {
        $groupOrder = array_keys((array) config('brand_wheel.group_labels', []));
        $groupLabels = (array) config('brand_wheel.group_labels', []);
        $diffMin = (int) config('brand_wheel.group_advantage_diff_min', 2);

        $totals = [];
        foreach ($groupOrder as $groupKey) {
            $itemsInGroup = array_values(array_filter($comparisonItems, fn (array $i) => $i['group'] === $groupKey));
            $selfCount = count(array_filter($itemsInGroup, fn (array $i) => $i['self_matched']));
            $competitorCount = count(array_filter($itemsInGroup, fn (array $i) => $i['competitor_matched']));
            $diff = $selfCount - $competitorCount;

            $totals[] = [
                'group' => $groupKey,
                'label' => (string) ($groupLabels[$groupKey] ?? $groupKey),
                'self_count' => $selfCount,
                'competitor_count' => $competitorCount,
                'max_count' => count($itemsInGroup),
                'verdict' => match (true) {
                    $diff >= $diffMin => 'self_advantage',
                    $diff <= -$diffMin => 'competitor_advantage',
                    default => 'even',
                },
            ];
        }

        return $totals;
    }

    private function state(bool $matched, bool $labelOnly): string
    {
        return match (true) {
            $matched => 'matched',
            $labelOnly => 'label_only',
            default => 'none',
        };
    }

    /**
     * @param  list<array{key: string, label_only_sub_elements: list<array{key: string}>}>  $axes
     * @return array<string, list<string>>
     */
    private function labelOnlyKeysByAxis(array $axes): array
    {
        $result = [];
        foreach ($axes as $axis) {
            $result[$axis['key']] = array_column((array) ($axis['label_only_sub_elements'] ?? []), 'key');
        }

        return $result;
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
