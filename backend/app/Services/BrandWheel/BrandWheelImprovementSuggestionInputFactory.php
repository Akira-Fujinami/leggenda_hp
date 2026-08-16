<?php

namespace App\Services\BrandWheel;

use App\Services\BrandWheel\Data\BrandWheelImprovementSuggestionInput;

/**
 * BrandWheelSubElementComparisonComposer::compose()の出力(24項目、config順)と
 * 自社/競合それぞれのevidenceルックアップ、グループ優劣(groupTotals)から、
 * 改善提案AIへ渡す入力DTOを組み立てる。実行難易度タグ(execution_difficulty)は
 * config('brand_wheel.axes.*.sub_element_execution_difficulty')(静的定義、
 * 企業ごとにAIへ判定させない)から引く。
 */
class BrandWheelImprovementSuggestionInputFactory
{
    // プロンプトへ渡すevidence抜粋の文字数上限。既存のBrandWheelImprovement
    // FocusComposer::EVIDENCE_MAX_CHARSと同じ理由(1件あたりの分量を抑える)
    // だが、こちらはPDF表示用ではなくAI入力のトークン量抑制が目的のため、
    // 少し余裕を持たせる。
    private const EVIDENCE_MAX_CHARS_FOR_PROMPT = 100;

    public function build(
        array $comparisonItems,
        array $selfEvidenceByAxisAndSubKey,
        array $competitorEvidenceByAxisAndSubKey,
        array $groupTotals,
        bool $hasCompetitor,
    ): BrandWheelImprovementSuggestionInput {
        $difficultyLabels = (array) config('brand_wheel.execution_difficulty_labels', []);

        $selfMatched = [];
        $selfUnmatched = [];
        $competitorMatched = [];
        $competitorUnmatched = [];

        foreach ($comparisonItems as $item) {
            if ($item['self_matched']) {
                $selfMatched[] = [
                    'axis_name' => $item['axis_name'],
                    'sub_name' => $item['sub_name'],
                    'evidence' => $this->capEvidence($selfEvidenceByAxisAndSubKey[$item['axis_key']][$item['sub_key']] ?? ''),
                ];
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
                }
            }
        }

        return new BrandWheelImprovementSuggestionInput(
            selfMatchedItems: $selfMatched,
            selfUnmatchedItems: $selfUnmatched,
            competitorMatchedItems: $competitorMatched,
            competitorUnmatchedItems: $competitorUnmatched,
            groupTotals: $hasCompetitor ? $groupTotals : [],
            hasCompetitor: $hasCompetitor,
        );
    }

    private function capEvidence(string $evidence): string
    {
        return mb_strlen($evidence) > self::EVIDENCE_MAX_CHARS_FOR_PROMPT
            ? mb_substr($evidence, 0, self::EVIDENCE_MAX_CHARS_FOR_PROMPT).'…'
            : $evidence;
    }
}
