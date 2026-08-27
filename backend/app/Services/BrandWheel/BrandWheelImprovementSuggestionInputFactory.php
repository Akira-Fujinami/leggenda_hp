<?php

namespace App\Services\BrandWheel;

use App\Services\BrandWheel\Data\BrandWheelImprovementSuggestionInput;

/**
 * BrandWheelSubElementComparisonComposer::compose()の出力(24項目、config順)と
 * 自社/競合それぞれのevidenceルックアップ、グループ優劣(groupTotals)から、
 * 改善提案AIへ渡す入力DTOを組み立てる。実行難易度タグ(execution_difficulty)は
 * config('brand_wheel.axes.*.sub_element_execution_difficulty')(静的定義、
 * 企業ごとにAIへ判定させない)から引く。
 *
 * 2026-08-20追加: 差別化テーマ選定に「自社の既存ブランド文脈」を考慮させる
 * ための自社強みデータ(selfConfirmedItemNames/selfCategoryScores)も、
 * このFactoryが同じ$comparisonItemsから決定的に集計する(依頼者指摘 ――
 * mutuallyUnmatchedItemsだけでは「両社とも書いていない」という消極的な
 * 理由でしかテーマを選べないため)。
 */
class BrandWheelImprovementSuggestionInputFactory
{
    // プロンプトへ渡すevidence抜粋の文字数上限。既存のBrandWheelImprovement
    // FocusComposer::EVIDENCE_MAX_CHARSと同じ理由(1件あたりの分量を抑える)
    // だが、こちらはPDF表示用ではなくAI入力のトークン量抑制が目的のため、
    // 少し余裕を持たせる。
    private const EVIDENCE_MAX_CHARS_FOR_PROMPT = 100;

    // key_messageはレポート向けの文字数上限(BrandWheelLeadResponseComposer)を
    // 持たないため、AI入力のトークン量抑制用に別途上限を設ける
    // (positive_impressionは同composerが既に65字上限で返すため、ここでは
    // 再度キャップしない)。
    private const KEY_MESSAGE_MAX_CHARS_FOR_PROMPT = 150;

    public function build(
        array $comparisonItems,
        array $selfEvidenceByAxisAndSubKey,
        array $competitorEvidenceByAxisAndSubKey,
        array $groupTotals,
        bool $hasCompetitor,
        ?string $selfKeyMessage = null,
        ?string $selfPositiveImpression = null,
        ?string $selfCoreValueEvidence = null,
        array $focusItemsForReason = [],
    ): BrandWheelImprovementSuggestionInput {
        $difficultyLabels = (array) config('brand_wheel.execution_difficulty_labels', []);

        $selfMatched = [];
        $selfUnmatched = [];
        $competitorMatched = [];
        $competitorUnmatched = [];
        $mutuallyUnmatched = [];
        $selfConfirmedItemNames = [];
        $selfCategoryScores = [];

        foreach ($comparisonItems as $item) {
            $selfCategoryScores[$item['axis_name']] ??= 0;
            $selfIsMatched = $item['self_matched'];
            $difficulty = null;

            if ($selfIsMatched) {
                $selfMatched[] = [
                    'axis_name' => $item['axis_name'],
                    'sub_name' => $item['sub_name'],
                    'evidence' => $this->capEvidence($selfEvidenceByAxisAndSubKey[$item['axis_key']][$item['sub_key']] ?? ''),
                ];
                $selfConfirmedItemNames[] = $item['sub_name'];
                $selfCategoryScores[$item['axis_name']]++;
            } else {
                $difficulty = (string) (config("brand_wheel.axes.{$item['axis_key']}.sub_element_execution_difficulty.{$item['sub_key']}") ?: 'medium');

                $selfUnmatched[] = [
                    'axis_name' => $item['axis_name'],
                    'sub_name' => $item['sub_name'],
                    'state' => $item['self_state'],
                    'execution_difficulty' => $difficulty,
                    'execution_note' => (string) ($difficultyLabels[$difficulty] ?? ''),
                ];
            }

            if ($hasCompetitor) {
                if ($item['competitor_matched']) {
                    $competitorMatched[] = [
                        'axis_name' => $item['axis_name'],
                        'sub_name' => $item['sub_name'],
                        'evidence' => $this->capEvidence($competitorEvidenceByAxisAndSubKey[$item['axis_key']][$item['sub_key']] ?? ''),
                    ];
                } else {
                    $competitorUnmatched[] = [
                        'axis_name' => $item['axis_name'],
                        'sub_name' => $item['sub_name'],
                        'state' => $item['competitor_state'],
                    ];

                    // 2026-08-19追加: 自社・競合とも未充足(=差別化提案の候補)。
                    // AI自身にself_unmatched_items×competitor_unmatched_itemsを
                    // 突き合わせさせるのではなく、ここで決定的に算出して渡す。
                    if (! $selfIsMatched) {
                        $mutuallyUnmatched[] = [
                            'axis_name' => $item['axis_name'],
                            'sub_name' => $item['sub_name'],
                            'execution_difficulty' => $difficulty,
                        ];
                    }
                }
            }
        }

        return new BrandWheelImprovementSuggestionInput(
            selfMatchedItems: $selfMatched,
            selfUnmatchedItems: $selfUnmatched,
            competitorMatchedItems: $competitorMatched,
            competitorUnmatchedItems: $competitorUnmatched,
            mutuallyUnmatchedItems: $hasCompetitor ? $mutuallyUnmatched : [],
            groupTotals: $hasCompetitor ? $groupTotals : [],
            hasCompetitor: $hasCompetitor,
            selfConfirmedItemNames: $selfConfirmedItemNames,
            selfCategoryScores: $selfCategoryScores,
            selfKeyMessage: $this->capText($selfKeyMessage, self::KEY_MESSAGE_MAX_CHARS_FOR_PROMPT),
            selfPositiveImpression: $selfPositiveImpression,
            selfCoreValueEvidence: $this->capText($selfCoreValueEvidence, self::EVIDENCE_MAX_CHARS_FOR_PROMPT),
            focusItemsForReason: $focusItemsForReason,
        );
    }

    private function capEvidence(string $evidence): string
    {
        return mb_strlen($evidence) > self::EVIDENCE_MAX_CHARS_FOR_PROMPT
            ? mb_substr($evidence, 0, self::EVIDENCE_MAX_CHARS_FOR_PROMPT).'…'
            : $evidence;
    }

    private function capText(?string $text, int $maxChars): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        return mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars).'…' : $text;
    }
}
