<?php

namespace Tests\Unit\Services\Report;

use App\Services\Report\ReportSummaryComposer;
use Tests\TestCase;

class ReportSummaryComposerTest extends TestCase
{
    private function composer(): ReportSummaryComposer
    {
        return new ReportSummaryComposer;
    }

    private function category(string $key, string $name, float $score, float $max, float $coverage): array
    {
        return ['key' => $key, 'name' => $name, 'score' => $score, 'configured_max_score' => $max, 'coverage_rate' => $coverage];
    }

    // ------------------------------------------------------------------
    // selectCategoryHighlights: 達成率(生スコアではない)で判定すること
    // ------------------------------------------------------------------

    public function test_highlight_selection_uses_achievement_ratio_not_raw_score(): void
    {
        // 修正1の worked example: technical_seo 12/20=60% vs accessibility 8/10=80%。
        // 生スコアだけを見るとtechnical_seo(12点)がaccessibility(8点)より
        // 高く見えるが、達成率で見ればaccessibilityの方が高い。
        $categories = [
            $this->category('technical_seo', '技術SEO', 12, 20, 100),
            $this->category('accessibility', 'アクセシビリティ', 8, 10, 100),
        ];

        $result = $this->composer()->selectCategoryHighlights($categories);

        $this->assertSame('normal', $result['status']);
        $this->assertSame('accessibility', $result['best']['key']);
        $this->assertSame('technical_seo', $result['worst']['key']);
    }

    public function test_categories_below_the_minimum_coverage_are_excluded_from_selection(): void
    {
        $categories = [
            $this->category('technical_seo', '技術SEO', 5, 20, 30.0), // カバー率50%未満 -> 除外
            $this->category('accessibility', 'アクセシビリティ', 8, 10, 100),
        ];

        $result = $this->composer()->selectCategoryHighlights($categories);

        // 唯一の評価対象カテゴリしか残らないため single 扱い。
        $this->assertSame('single', $result['status']);
        $this->assertSame('accessibility', $result['best']['key']);
    }

    public function test_no_eligible_category_returns_none_status(): void
    {
        $categories = [
            $this->category('technical_seo', '技術SEO', 0, 20, 0.0),
            $this->category('authority', '外部評価', 0, 15, 0.0),
        ];

        $result = $this->composer()->selectCategoryHighlights($categories);

        $this->assertSame('none', $result['status']);
        $this->assertNull($result['best']);
    }

    public function test_a_category_with_zero_configured_max_score_is_excluded(): void
    {
        $categories = [
            $this->category('technical_seo', '技術SEO', 0, 0, 100),
            $this->category('accessibility', 'アクセシビリティ', 8, 10, 100),
        ];

        $result = $this->composer()->selectCategoryHighlights($categories);

        $this->assertSame('single', $result['status']);
        $this->assertSame('accessibility', $result['best']['key']);
    }

    public function test_a_single_eligible_category_is_both_best_and_worst(): void
    {
        $categories = [$this->category('accessibility', 'アクセシビリティ', 8, 10, 100)];

        $result = $this->composer()->selectCategoryHighlights($categories);

        $this->assertSame('single', $result['status']);
        $this->assertSame('accessibility', $result['best']['key']);
        $this->assertSame('accessibility', $result['worst']['key']);
    }

    public function test_equal_achievement_ratios_across_all_eligible_categories_is_a_tie(): void
    {
        $categories = [
            $this->category('technical_seo', '技術SEO', 10, 20, 100),
            $this->category('accessibility', 'アクセシビリティ', 5, 10, 100),
        ];

        $result = $this->composer()->selectCategoryHighlights($categories);

        $this->assertSame('tie', $result['status']);
    }

    // ------------------------------------------------------------------
    // composeOverallSummary: 分岐込みの総評文
    // ------------------------------------------------------------------

    private function selfScore(int $displayScore, float $coverageRate, array $categoryScores): array
    {
        return ['display_score' => $displayScore, 'coverage_rate' => $coverageRate, 'category_scores' => $categoryScores];
    }

    public function test_summary_uses_reference_score_wording_below_the_coverage_threshold(): void
    {
        $score = $this->selfScore(56, 40.0, [$this->category('accessibility', 'アクセシビリティ', 8, 10, 100)]);

        $summary = $this->composer()->composeOverallSummary($score, recommendationCount: 0, displayCompanyName: 'サンプル株式会社様');

        $this->assertStringContainsString('参考スコア', $summary);
        $this->assertStringContainsString('56点', $summary);
    }

    public function test_summary_does_not_use_reference_score_wording_at_or_above_the_threshold(): void
    {
        $score = $this->selfScore(85, 90.0, [$this->category('accessibility', 'アクセシビリティ', 8, 10, 100)]);

        $summary = $this->composer()->composeOverallSummary($score, recommendationCount: 0, displayCompanyName: 'サンプル株式会社様');

        $this->assertStringNotContainsString('参考スコア', $summary);
    }

    public function test_summary_never_praises_a_category_below_the_high_ratio_threshold(): void
    {
        // 達成率40%のカテゴリを「高く評価されています」と書いてはならない。
        $score = $this->selfScore(40, 90.0, [
            $this->category('technical_seo', '技術SEO', 4, 10, 100), // 40%
        ]);

        $summary = $this->composer()->composeOverallSummary($score, recommendationCount: 0, displayCompanyName: '様');

        $this->assertStringNotContainsString('高く評価されています', $summary);
    }

    public function test_summary_omits_the_recommendation_pointer_when_there_are_no_recommendations(): void
    {
        $score = $this->selfScore(85, 90.0, [$this->category('accessibility', 'アクセシビリティ', 8, 10, 100)]);

        $summary = $this->composer()->composeOverallSummary($score, recommendationCount: 0, displayCompanyName: '様');

        $this->assertStringNotContainsString('次のページ', $summary);
    }

    public function test_summary_includes_the_recommendation_pointer_when_recommendations_exist(): void
    {
        $score = $this->selfScore(85, 90.0, [$this->category('accessibility', 'アクセシビリティ', 8, 10, 100)]);

        $summary = $this->composer()->composeOverallSummary($score, recommendationCount: 3, displayCompanyName: '様');

        $this->assertStringContainsString('次のページ', $summary);
    }

    public function test_summary_mentions_only_the_weak_category_when_no_category_is_strong(): void
    {
        $score = $this->selfScore(50, 90.0, [
            $this->category('technical_seo', '技術SEO', 6, 20, 100), // 30% -> weak
            $this->category('performance', '表示速度', 10, 15, 100), // 66.7% -> neither strong nor weak
        ]);

        $summary = $this->composer()->composeOverallSummary($score, recommendationCount: 0, displayCompanyName: '様');

        $this->assertStringNotContainsString('高く評価されています', $summary);
        $this->assertStringContainsString('技術SEO', $summary);
        $this->assertStringContainsString('改善の余地があります', $summary);
    }

    public function test_summary_uses_the_no_eligible_category_fallback_wording(): void
    {
        $score = $this->selfScore(0, 0.0, [$this->category('technical_seo', '技術SEO', 0, 20, 0.0)]);

        $summary = $this->composer()->composeOverallSummary($score, recommendationCount: 0, displayCompanyName: '様');

        $this->assertStringContainsString('特定は今回できませんでした', $summary);
    }

    public function test_summary_uses_the_single_eligible_category_fallback_wording(): void
    {
        $score = $this->selfScore(80, 90.0, [
            $this->category('technical_seo', '技術SEO', 2, 20, 10.0), // 除外(カバー率不足)
            $this->category('accessibility', 'アクセシビリティ', 9, 10, 100), // 唯一の評価対象
        ]);

        $summary = $this->composer()->composeOverallSummary($score, recommendationCount: 0, displayCompanyName: '様');

        $this->assertStringContainsString('判定を保留しています', $summary);
        $this->assertStringContainsString('アクセシビリティ', $summary);
    }

    public function test_summary_uses_the_tie_fallback_wording_instead_of_contradicting_itself(): void
    {
        $score = $this->selfScore(70, 90.0, [
            $this->category('technical_seo', '技術SEO', 10, 20, 100),
            $this->category('accessibility', 'アクセシビリティ', 5, 10, 100),
        ]);

        $summary = $this->composer()->composeOverallSummary($score, recommendationCount: 0, displayCompanyName: '様');

        $this->assertStringContainsString('同水準', $summary);
        $this->assertStringNotContainsString('高く評価されています', $summary);
        $this->assertStringNotContainsString('改善の余地があります', $summary);
    }

    // ------------------------------------------------------------------
    // composeComparisonSentence: カバー率が近いときのみ断定する
    // ------------------------------------------------------------------

    private function overallScore(float $overall, int $display, float $coverage): array
    {
        return ['overall_score' => $overall, 'display_score' => $display, 'coverage_rate' => $coverage];
    }

    public function test_comparison_asserts_a_difference_when_coverage_is_close_and_score_gap_is_meaningful(): void
    {
        $self = $this->overallScore(75.0, 75, 95.0);
        $competitor = $this->overallScore(60.0, 60, 90.0);

        $sentence = $this->composer()->composeComparisonSentence($self, $competitor);

        $this->assertStringContainsString('上回りました', $sentence);
        $this->assertStringContainsString('75点', $sentence);
        $this->assertStringContainsString('60点', $sentence);
    }

    public function test_comparison_says_no_big_difference_when_score_gap_is_small(): void
    {
        $self = $this->overallScore(75.4, 75, 95.0);
        $competitor = $this->overallScore(75.0, 75, 92.0);

        $sentence = $this->composer()->composeComparisonSentence($self, $competitor);

        $this->assertStringContainsString('大きな差はありませんでした', $sentence);
    }

    public function test_comparison_gives_a_caveat_when_coverage_gap_is_large_even_if_score_gap_is_large(): void
    {
        $self = $this->overallScore(90.0, 90, 100.0);
        $competitor = $this->overallScore(50.0, 50, 78.0); // カバー率差22pt >= 20pt

        $sentence = $this->composer()->composeComparisonSentence($self, $competitor);

        $this->assertStringContainsString('参考程度にとどめてください', $sentence);
    }

    public function test_comparison_gives_a_caveat_when_either_site_is_below_the_low_coverage_safety_net(): void
    {
        // 既存の比較画面がカバー率50%未満に「データ不足」バッジを出す安全装置を、
        // カバー率差が20pt未満でも迂回してはならない。
        $self = $this->overallScore(90.0, 90, 45.0); // 50%未満
        $competitor = $this->overallScore(50.0, 50, 55.0); // 差はわずか10pt

        $sentence = $this->composer()->composeComparisonSentence($self, $competitor);

        $this->assertStringContainsString('参考程度にとどめてください', $sentence);
    }
}
