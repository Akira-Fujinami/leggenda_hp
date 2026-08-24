<?php

namespace App\Services\BrandWheel;

use Illuminate\Support\Facades\Log;

/**
 * 「改善提案」ページ(ブランド・ホイール起点)の「グループ差の特定+3項目+
 * 比較サイトの実際のevidence」を、決定的な規則で機械的に導出する。AIには
 * 一切追加で判断させない(docs/lead-report-layout/README.md「【2】設計の要」
 * 「【3】決定的な規則が必要な箇所」)。
 *
 * 【グループ選定規則】3グループ(company_appeal/company_distance/job_appeal)
 * それぞれについて「競合の該当件数の合計 - 自社の該当件数の合計」を計算し、
 * 最大のグループを選ぶ。同点の場合はconfig('brand_wheel.group_labels')の
 * 並び順で先に来るグループを選ぶ(config順=決定的な規則。実行のたびに
 * 順序が変わるAIの出力順や配列のキー順に依存させない)。
 *
 * 【3項目選定規則】選ばれたグループに属する下位要素のうち「競合にはあり、
 * 自社には無い」もの(BrandWheelSubElementComparisonComposerの出力順、
 * つまりconfig('brand_wheel.axes')の並び順)を先頭から最大3件選ぶ。3件に
 * 満たない場合はそのまま少ない件数で成立させ、他グループから補充しない
 * (グループ差の主張と選定項目のグループを一致させ続けるため)。
 */
class BrandWheelImprovementFocusComposer
{
    private const MAX_ITEMS = 3;

    // 2026-08-10: 110→60に縮小。カードのheight固定を廃止しauto(内容に追従)に
    // した後も、3枚とも上限文字数に近い引用が並ぶ実データ(実測)で
    // ページ下部の余白が190mm上限の残り2.6mmまで縮む不具合が見つかった
    // (ユーザー指摘の「p6の余裕が8.5mm」よりさらに厳しい真の最悪ケース、
    // 110字上限では不十分だった)。70字でも8.5mmで不合格、60字で実PDF確認
    // 14.4mmを確保(50字でも同じ14.4mmだったため、より多く引用を見せられる
    // 60字を採用)。3枚とも60字ちょうどの引用が並ぶケースを実データ検証済み。
    private const EVIDENCE_MAX_CHARS = 60;

    /**
     * @param  list<array{axis_key: string, axis_name: string, group: string, sub_key: string, sub_name: string, definition: string, recommendation: string, self_matched: bool, competitor_matched: bool}>  $comparisonItems  BrandWheelSubElementComparisonComposer::compose()の戻り値
     * @param  array<string, array<string, string>>  $competitorEvidenceByAxisAndSubKey  (axis_key => (sub_key => evidence))。選ばれた項目の分だけ実際に使う ―― 比較サイトの本文をこのページの目的以外に広く露出させないため、呼び出し側もこの用途専用に組み立てたものを渡すこと
     * @return array{selected_group: string, groups: list<array{group: string, label: string, self_count: int, competitor_count: int, max_count: int}>, items: list<array{axis_name: string, sub_name: string, definition: string, recommendation: string, competitor_evidence: ?string}>}|null nullは項目が1件も無い場合(呼び出し側でこのページ自体を出さない判断に使う)
     */
    public function compose(array $comparisonItems, array $competitorEvidenceByAxisAndSubKey): ?array
    {
        if ($comparisonItems === []) {
            return null;
        }

        $groupOrder = array_keys((array) config('brand_wheel.group_labels', []));
        $groupLabels = (array) config('brand_wheel.group_labels', []);

        $groups = [];
        foreach ($groupOrder as $groupKey) {
            $itemsInGroup = array_values(array_filter($comparisonItems, fn (array $i) => $i['group'] === $groupKey));

            $groups[$groupKey] = [
                'group' => $groupKey,
                'label' => (string) ($groupLabels[$groupKey] ?? $groupKey),
                'self_count' => count(array_filter($itemsInGroup, fn (array $i) => $i['self_matched'])),
                'competitor_count' => count(array_filter($itemsInGroup, fn (array $i) => $i['competitor_matched'])),
                'max_count' => count($itemsInGroup),
            ];
        }

        $selectedGroupKey = $groupOrder[0] ?? null;
        $selectedGap = null;

        foreach ($groupOrder as $groupKey) {
            $gap = $groups[$groupKey]['competitor_count'] - $groups[$groupKey]['self_count'];

            // `>`(以上ではない)で比較することで、同点のときはforeachの走査順
            // (=config('brand_wheel.group_labels')の並び順で先に来るほう)を
            // 上書きしない ―― これが上記の同点規則の実装。
            if ($selectedGap === null || $gap > $selectedGap) {
                $selectedGap = $gap;
                $selectedGroupKey = $groupKey;
            }
        }

        $candidateItems = array_values(array_filter(
            $comparisonItems,
            fn (array $i) => $i['group'] === $selectedGroupKey && $i['competitor_matched'] && ! $i['self_matched'],
        ));

        $items = array_map(fn (array $i) => [
            'axis_name' => $i['axis_name'],
            'sub_name' => $i['sub_name'],
            'definition' => $i['definition'],
            'recommendation' => $i['recommendation'],
            'competitor_evidence' => $this->capEvidence($competitorEvidenceByAxisAndSubKey[$i['axis_key']][$i['sub_key']] ?? null),
        ], array_slice($candidateItems, 0, self::MAX_ITEMS));

        return [
            'selected_group' => (string) $selectedGroupKey,
            'groups' => array_values($groups),
            'items' => $items,
        ];
    }

    /**
     * 2026-08-10: 競合が無い(または競合を読み取れなかった)診断向けの改善提案。
     * ユーザー指示: 「比較サイトが無いため、領域ごとの比較はご用意できません。」
     * の1行だけでページの大半が空白になり、営業資料として成立しないため、
     * 「御社のサイトで触れられていなかった項目」を示す構成に置き換える
     * (最終ページの「3〜5社と比較しませんか」への導線として機能させる)。
     *
     * 【グループ選定規則】3グループそれぞれについて自社の「－」
     * (self_state==='none')の件数を数え、最も多いグループを選ぶ
     * (同点はconfig順)。ただし選んだグループに「－」も「△」
     * (self_state==='label_only')も0件(=そのグループが8/8で○のみ)の場合は、
     * 「－」が次に多いグループへ移る ―― 実装上は「－の件数降順・同点は
     * config順」で並べたグループ一覧を先頭から見て、(－件数+△件数)>0の
     * 最初のグループを採用する形にしている(3グループしか無いため同じ結果)。
     *
     * 【3項目選定規則】選ばれたグループ内の「－」項目をconfig順に並べ、
     * 3件に満たない場合は同グループの「△」項目(config順)で埋める(－が
     * 優先、△は「見出し・リンクラベルのみで具体性が無い」ため次点)。
     *
     * 【ページを出さない条件】どのグループも(－件数+△件数)が0、つまり
     * 自社の24項目すべてが○の場合のみnullを返す(24/24は実運用ではまず
     * 起きないが、白紙ページを作らないための保険としてログに記録する)。
     *
     * @param  list<array{axis_key: string, axis_name: string, group: string, sub_key: string, sub_name: string, definition: string, recommendation: string, self_matched: bool, competitor_matched: bool, self_state: string, competitor_state: string}>  $comparisonItems  BrandWheelSubElementComparisonComposer::compose()の戻り値
     * @return array{selected_group: string, groups: list<array{group: string, label: string, self_count: int, max_count: int}>, items: list<array{axis_name: string, sub_name: string, definition: string, recommendation: string, self_reason: string}>}|null nullは自社24項目すべてが○の場合(呼び出し側でこのページ自体を出さない判断に使う)
     */
    public function composeSelfOnly(array $comparisonItems): ?array
    {
        if ($comparisonItems === []) {
            return null;
        }

        $groupOrder = array_keys((array) config('brand_wheel.group_labels', []));
        $groupLabels = (array) config('brand_wheel.group_labels', []);

        $groups = [];
        $noneCounts = [];
        $labelOnlyCounts = [];
        foreach ($groupOrder as $groupKey) {
            $itemsInGroup = array_values(array_filter($comparisonItems, fn (array $i) => $i['group'] === $groupKey));
            $noneCounts[$groupKey] = count(array_filter($itemsInGroup, fn (array $i) => $i['self_state'] === 'none'));
            $labelOnlyCounts[$groupKey] = count(array_filter($itemsInGroup, fn (array $i) => $i['self_state'] === 'label_only'));

            $groups[$groupKey] = [
                'group' => $groupKey,
                'label' => (string) ($groupLabels[$groupKey] ?? $groupKey),
                'self_count' => count(array_filter($itemsInGroup, fn (array $i) => $i['self_matched'])),
                'max_count' => count($itemsInGroup),
            ];
        }

        // 「－」件数降順でグループを並べ、(－+△)>0の最初のグループを採用する。
        // 同点はconfig順を保持する必要があるため、PHPのusort/sortが安定
        // ソート(同値要素の相対順序を保持、PHP 8.0+で保証)であることに
        // 依拠せず、Collection::sortByDesc()(同じく安定ソート)を使い、
        // 入力自体がconfig順の$groupOrderであることでconfig順維持を保証する。
        $rankedGroupKeys = collect($groupOrder)
            ->sortByDesc(fn (string $g) => $noneCounts[$g])
            ->values()
            ->all();

        $selectedGroupKey = null;
        foreach ($rankedGroupKeys as $groupKey) {
            if ($noneCounts[$groupKey] + $labelOnlyCounts[$groupKey] > 0) {
                $selectedGroupKey = $groupKey;
                break;
            }
        }

        if ($selectedGroupKey === null) {
            // 自社24項目すべてが○(実運用ではまず起きない)。
            Log::info('Brand wheel improvement focus (self-only): all 24 items are ○, page omitted', [
                'group_none_counts' => $noneCounts,
                'group_label_only_counts' => $labelOnlyCounts,
            ]);

            return null;
        }

        $noneItems = array_values(array_filter(
            $comparisonItems,
            fn (array $i) => $i['group'] === $selectedGroupKey && $i['self_state'] === 'none',
        ));
        $labelOnlyItems = array_values(array_filter(
            $comparisonItems,
            fn (array $i) => $i['group'] === $selectedGroupKey && $i['self_state'] === 'label_only',
        ));

        $candidateItems = array_map(fn (array $i) => $i + ['self_reason' => 'none'], $noneItems);
        $candidateItems = array_merge($candidateItems, array_map(fn (array $i) => $i + ['self_reason' => 'label_only'], $labelOnlyItems));

        $items = array_map(fn (array $i) => [
            'axis_name' => $i['axis_name'],
            'sub_name' => $i['sub_name'],
            'definition' => $i['definition'],
            'recommendation' => $i['recommendation'],
            'self_reason' => $i['self_reason'],
        ], array_slice($candidateItems, 0, self::MAX_ITEMS));

        return [
            'selected_group' => (string) $selectedGroupKey,
            'groups' => array_values($groups),
            'items' => $items,
        ];
    }

    /**
     * 2026-08-09: 引用(比較サイトの実際のevidence)に文字数上限を設ける。
     * カード側(.rcard)はheight固定をやめてauto(内容に追従)にしたため、
     * このcapが無くても重なりは起きなくなったが、極端に長い引用1件で
     * カード3枚が同じ行として大きく伸び、改善提案ページ自体が7ページ枠を
     * 超える(=対比表等が次ページへ押し出される)ことを防ぐための保険
     * (ユーザー承認の「上限付き」案と同じ考え方)。
     */
    private function capEvidence(?string $evidence): ?string
    {
        if ($evidence === null) {
            return null;
        }

        return BrandWheelTextTruncator::truncateAtSentenceBoundary($evidence, self::EVIDENCE_MAX_CHARS);
    }
}
