<?php

namespace App\Services\BrandWheel;

/**
 * 自社/競合それぞれの合計matched件数(24項目中)が、比較や個別提案の根拠として
 * 十分な情報量かどうかを判定する(2026-08-25、実物レポート32の指摘への対応 ――
 * 24項目中1件しか読み取れていない競合を根拠に「差を埋めましょう」と提案する等、
 * 少ない件数から比較に基づく主張を組み立ててしまう問題)。
 *
 * BrandWheelReportEligibility(matched>0か、レポートを生成するかどうか・
 * 診断回数消費に関わる)とは別の関心事。こちらは「(生成される)レポートの
 * 中で、比較に基づく主張をしてよいか」を判定する。両者を統合すると
 * 「レポートを出すかどうか」と「レポート内で何を言うか」が1つの判定に
 * 混ざるため、意図的に別クラスとしている。
 *
 * 呼び出し側(ReportViewModelBuilder/GenerateBrandWheelImprovementSuggestionJob)が
 * それぞれ既に持っている合計matched件数に対してこれを呼ぶだけで、件数の
 * 再計算はしない ―― 判定ロジック(閾値そのもの)だけをここに集約する。
 */
class BrandWheelComparisonSufficiency
{
    public function isSufficient(int $totalMatched): bool
    {
        return $totalMatched >= (int) config('brand_wheel.comparison_sufficiency_threshold', 6);
    }
}
