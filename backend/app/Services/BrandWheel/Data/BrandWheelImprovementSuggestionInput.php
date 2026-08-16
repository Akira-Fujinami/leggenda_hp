<?php

namespace App\Services\BrandWheel\Data;

/**
 * 改善提案AI(BrandWheelImprovementSuggestionProvider)へ渡す、完全にPHP側で
 * 事前計算済みのグラウンディング済みデータ。AIはこの中に無い事実を作り出さない
 * よう明示的に指示される(BrandWheelImprovementSuggestionInputFactory参照)。
 *
 * competitorMatchedItems/competitorUnmatchedItemsは$hasCompetitor=trueの
 * ときのみ非空になる。executionDifficulty/executionNoteは
 * config('brand_wheel.axes.*.sub_element_execution_difficulty')・
 * execution_difficulty_labelsから来る静的な事実(企業ごとにAIへ判定させない)。
 */
readonly class BrandWheelImprovementSuggestionInput
{
    /**
     * @param  list<array{axis_name: string, sub_name: string, evidence: string}>  $selfMatchedItems  自社で確認できた項目(検証済みevidence付き)
     * @param  list<array{axis_name: string, sub_name: string, state: string, execution_difficulty: string, execution_note: string}>  $selfUnmatchedItems  自社で確認できなかった項目(state: none/label_only)
     * @param  list<array{axis_name: string, sub_name: string, evidence: string}>  $competitorMatchedItems  競合で確認できた項目(自社には無いもの、$hasCompetitor時のみ)
     * @param  list<array{axis_name: string, sub_name: string, state: string}>  $competitorUnmatchedItems  競合でも確認できなかった項目($hasCompetitor時のみ)
     * @param  list<array{group: string, label: string, self_count: int, competitor_count: int, max_count: int, verdict: string}>  $groupTotals  3グループの自社/競合件数と優劣($hasCompetitor時のみ非空)
     */
    public function __construct(
        public array $selfMatchedItems,
        public array $selfUnmatchedItems,
        public array $competitorMatchedItems,
        public array $competitorUnmatchedItems,
        public array $groupTotals,
        public bool $hasCompetitor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'self_matched_items' => $this->selfMatchedItems,
            'self_unmatched_items' => $this->selfUnmatchedItems,
            'competitor_matched_items' => $this->competitorMatchedItems,
            'competitor_unmatched_items' => $this->competitorUnmatchedItems,
            'group_totals' => $this->groupTotals,
            'has_competitor' => $this->hasCompetitor,
        ];
    }
}
