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
 *
 * 2026-08-19追加: mutuallyUnmatchedItems(自社・競合の両方が未充足の項目)。
 * 「差別化提案」の候補プールをAI自身にselfUnmatchedItems×competitorUnmatched
 * Itemsの突き合わせで探させるのではなく、PHP側で決定的に事前計算して渡す
 * (既存の設計原則 ―― 判定できる事実はPHPで計算し、AIには事実の上での
 * 判断のみを委ねる、docs/lead-report-layout/README.md「設計の要」)。
 * $hasCompetitor=falseのときは常に空。
 *
 * 2026-08-20追加: 差別化テーマ選定に「自社の既存ブランド文脈」を考慮させる
 * ための追加事実(selfConfirmedItemNames/selfCategoryScores/selfKeyMessage/
 * selfPositiveImpression/selfCoreValueEvidence)。依頼者指摘 ――
 * mutuallyUnmatchedItemsだけから選ぶと「両社とも書いていない」という
 * 消極的な理由だけで選ばれてしまい、自社らしさとの接続を説明できない
 * テーマが出ることがあったため、自社が既に確認できている強み・パーパス・
 * 印象を判断材料として明示的に追加した。いずれも既存のBrandWheelAnalysis
 * Result/BrandWheelLeadResponseComposerが既に検証済みの事実の再利用であり、
 * このFactory・DTOが新たに何かを生成することはない。
 */
readonly class BrandWheelImprovementSuggestionInput
{
    /**
     * @param  list<array{axis_name: string, sub_name: string, evidence: string}>  $selfMatchedItems  自社で確認できた項目(検証済みevidence付き)
     * @param  list<array{axis_name: string, sub_name: string, state: string, execution_difficulty: string, execution_note: string}>  $selfUnmatchedItems  自社で確認できなかった項目(state: none/label_only)
     * @param  list<array{axis_name: string, sub_name: string, evidence: string}>  $competitorMatchedItems  競合で確認できた項目(自社には無いもの、$hasCompetitor時のみ)
     * @param  list<array{axis_name: string, sub_name: string, state: string}>  $competitorUnmatchedItems  競合でも確認できなかった項目($hasCompetitor時のみ)
     * @param  list<array{axis_name: string, sub_name: string, execution_difficulty: string}>  $mutuallyUnmatchedItems  自社・競合とも確認できなかった項目(差別化提案の候補プール、$hasCompetitor時のみ)
     * @param  list<array{group: string, label: string, self_count: int, competitor_count: int, max_count: int, verdict: string}>  $groupTotals  3グループの自社/競合件数と優劣($hasCompetitor時のみ非空)
     * @param  list<string>  $selfConfirmedItemNames  自社で確認できた下位要素名の一覧(selfMatchedItemsのsub_nameのみを抽出したもの、差別化テーマとの接続性判断用)
     * @param  array<string, int>  $selfCategoryScores  自社の軸(6軸)ごとの確認済み件数({活動的魅力: 2, 資産的魅力: 0, ...}、0件の軸も必ず含む)
     * @param  ?string  $selfKeyMessage  自社のキーメッセージ(BrandWheelLeadResponseComposer::compose()のkey_message、検証済み)
     * @param  ?string  $selfPositiveImpression  自社のポジティブな印象(同composerのpositive_impression、検証済み)
     * @param  ?string  $selfCoreValueEvidence  自社のCore Value根拠(BrandWheelAnalysisResult.core_value_evidence、core_value_readable=trueのときのみ非null。原文照合済み)
     */
    public function __construct(
        public array $selfMatchedItems,
        public array $selfUnmatchedItems,
        public array $competitorMatchedItems,
        public array $competitorUnmatchedItems,
        public array $mutuallyUnmatchedItems,
        public array $groupTotals,
        public bool $hasCompetitor,
        public array $selfConfirmedItemNames,
        public array $selfCategoryScores,
        public ?string $selfKeyMessage,
        public ?string $selfPositiveImpression,
        public ?string $selfCoreValueEvidence,
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
            'mutually_unmatched_items' => $this->mutuallyUnmatchedItems,
            'group_totals' => $this->groupTotals,
            'has_competitor' => $this->hasCompetitor,
            'self_confirmed_items' => $this->selfConfirmedItemNames,
            'self_category_scores' => $this->selfCategoryScores,
            'self_key_message' => $this->selfKeyMessage,
            'self_positive_impression' => $this->selfPositiveImpression,
            'self_core_value_evidence' => $this->selfCoreValueEvidence,
        ];
    }
}
