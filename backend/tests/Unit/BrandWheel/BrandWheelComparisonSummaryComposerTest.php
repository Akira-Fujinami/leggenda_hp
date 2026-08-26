<?php

namespace Tests\Unit\BrandWheel;

use App\Services\BrandWheel\BrandWheelComparisonSummaryComposer;
use Tests\TestCase;

/**
 * 【自社ページ】【他社ページ】【ワンポイント】。いずれもAIには書かせず、
 * 件数から機械的に導出する(2026-08-03のユーザー指摘)。
 */
class BrandWheelComparisonSummaryComposerTest extends TestCase
{
    private function composer(): BrandWheelComparisonSummaryComposer
    {
        return new BrandWheelComparisonSummaryComposer;
    }

    private function axis(string $key, string $group, string $name, int $matchedCount, int $maxCount = 4): array
    {
        return ['key' => $key, 'group' => $group, 'name' => $name, 'matched_count' => $matchedCount, 'max_count' => $maxCount];
    }

    public function test_points_returns_empty_array_when_axes_is_empty(): void
    {
        $this->assertSame([], $this->composer()->points([]));
    }

    public function test_points_names_the_most_filled_axis(): void
    {
        $axes = [
            $this->axis('will_activity', 'company_appeal', '活動的魅力', 3),
            $this->axis('asset', 'company_appeal', '資産的魅力', 1),
        ];

        $points = $this->composer()->points($axes);

        $this->assertContains('活動的魅力が最も内容として充足しています。', $points);
    }

    public function test_points_flags_every_zero_count_axis(): void
    {
        $axes = [
            $this->axis('will_activity', 'company_appeal', '活動的魅力', 2),
            $this->axis('asset', 'company_appeal', '資産的魅力', 0),
            $this->axis('personality', 'company_distance', '人間的魅力', 0),
        ];

        $points = $this->composer()->points($axes);

        $this->assertContains('資産的魅力に関する記述はサイト上では確認できませんでした。', $points);
        $this->assertContains('人間的魅力に関する記述はサイト上では確認できませんでした。', $points);
    }

    public function test_points_flags_a_sparse_group_relative_to_the_max_group(): void
    {
        // company_appeal合計=8, company_distance合計=1
        // (デフォルト閾値0.5 → 1 < 8*0.5=4 なので該当)。
        $axes = [
            $this->axis('will_activity', 'company_appeal', '活動的魅力', 4),
            $this->axis('asset', 'company_appeal', '資産的魅力', 4),
            $this->axis('personality', 'company_distance', '人間的魅力', 1),
            $this->axis('relationship', 'company_distance', '関係性', 0),
        ];

        $points = $this->composer()->points($axes);

        $this->assertContains('全体的に会社との距離の情報は少なめです。', $points);
    }

    public function test_one_point_returns_null_when_axes_is_empty(): void
    {
        $this->assertNull($this->composer()->onePoint([]));
    }

    public function test_one_point_selects_insufficient_content_when_two_or_more_axes_are_zero(): void
    {
        $axes = array_merge(
            array_fill(0, 2, $this->axis('a', 'company_appeal', 'A', 0)),
            array_fill(0, 4, $this->axis('b', 'company_appeal', 'B', 2)),
        );

        $result = $this->composer()->onePoint($axes);

        $this->assertSame('insufficient_content', $result['key']);
    }

    public function test_one_point_selects_uniform_low_when_all_axes_are_evenly_low(): void
    {
        // 全軸1/4(比率0.25)。平均0.25<=0.5、spread=0<=0.25 → uniform_low。
        $axes = array_fill(0, 6, $this->axis('a', 'company_appeal', 'A', 1, 4));

        $result = $this->composer()->onePoint($axes);

        $this->assertSame('uniform_low', $result['key']);
    }

    public function test_one_point_selects_well_covered_when_axes_are_high_or_uneven(): void
    {
        // 全軸3/4(比率0.75、平均が閾値0.5超) → well_covered。
        $axes = array_fill(0, 6, $this->axis('a', 'company_appeal', 'A', 3, 4));

        $result = $this->composer()->onePoint($axes);

        $this->assertSame('well_covered', $result['key']);
    }

    public function test_one_point_only_considers_the_axes_passed_in_not_a_separate_competitor_argument(): void
    {
        // onePoint()は自社軸のみを受け取るシグネチャのため、競合の状態で
        // 分岐しないことは呼び出し側(LeadAnalysisController)の責務でもあるが、
        // ここではメソッド自体が単一引数であることを型シグネチャで保証している
        // ことを明示的に確認する(誤って第2引数を追加しないためのドキュメント)。
        $reflection = new \ReflectionMethod(BrandWheelComparisonSummaryComposer::class, 'onePoint');
        $this->assertCount(1, $reflection->getParameters());
    }

    public function test_one_point_result_always_has_a_text_and_a_key(): void
    {
        foreach ([
            array_fill(0, 6, $this->axis('a', 'company_appeal', 'A', 0)),
            array_fill(0, 6, $this->axis('a', 'company_appeal', 'A', 1, 4)),
            array_fill(0, 6, $this->axis('a', 'company_appeal', 'A', 4, 4)),
        ] as $axes) {
            $result = $this->composer()->onePoint($axes);
            $this->assertNotEmpty($result['key']);
            $this->assertNotEmpty($result['text']);
        }
    }

    /**
     * 社外に出る文章のため、ブランド・ホイールと同じ否定的評価語を
     * 一切含まないこと(2026-08-03のユーザー指摘)。
     */
    public function test_one_point_and_comparison_summary_messages_contain_no_forbidden_phrase(): void
    {
        $forbiddenPhrases = (array) config('brand_wheel.forbidden_phrases');
        $this->assertNotEmpty($forbiddenPhrases);

        $texts = array_merge(
            array_values((array) config('brand_wheel.one_point_messages')),
            array_values((array) config('brand_wheel.comparison_summary_templates')),
        );

        foreach ($texts as $text) {
            foreach ($forbiddenPhrases as $phrase) {
                $this->assertStringNotContainsString($phrase, $text, "text '{$text}' contains forbidden phrase '{$phrase}'");
            }
        }
    }

    // ------------------------------------------------------------------
    // 2026-08-17改修分: pointsForReport()の根拠付き文言・comparisonOverview()。
    // ------------------------------------------------------------------

    /**
     * @return array{key: string, group: string, name: string, matched_count: int, max_count: int, matched_sub_elements: list<array{key: string, name: string}>}
     */
    private function axisWithEvidence(string $key, string $group, string $name, array $matchedSubElements, int $maxCount = 4): array
    {
        return [
            'key' => $key, 'group' => $group, 'name' => $name,
            'matched_count' => count($matchedSubElements), 'max_count' => $maxCount,
            'matched_sub_elements' => $matchedSubElements,
        ];
    }

    public function test_points_for_report_names_the_most_filled_axis_with_its_matched_sub_element_evidence(): void
    {
        $axes = [
            $this->axisWithEvidence('will_activity', 'company_appeal', '活動的魅力', [
                ['key' => 'business_expansion', 'name' => '展開事業・商品'],
                ['key' => 'project_initiative', 'name' => 'PJ・新たな取組'],
            ]),
            $this->axisWithEvidence('asset', 'company_appeal', '資産的魅力', []),
        ];

        $points = $this->composer()->pointsForReport($axes);

        $this->assertContains(
            '活動的魅力について、展開事業・商品・PJ・新たな取組に関する記述が確認でき、情報は比較的充実しています。',
            $points,
        );
    }

    public function test_points_for_report_falls_back_to_the_plain_template_when_matched_sub_elements_is_absent(): void
    {
        // matched_sub_elementsキー自体を持たないaxes配列(古いデータ形状)でも
        // 落ちない ―― 根拠(下位要素名)が無ければ従来のmost_filled_axis
        // テンプレートにフォールバックする。
        $axes = [$this->axis('will_activity', 'company_appeal', '活動的魅力', 2)];

        $points = $this->composer()->pointsForReport($axes);

        $this->assertContains('活動的魅力が最も内容として充足しています。', $points);
    }

    public function test_comparison_overview_states_the_totals_and_each_group_verdict(): void
    {
        $groupTotals = [
            ['group' => 'company_appeal', 'label' => '会社の魅力', 'self_count' => 2, 'competitor_count' => 2, 'max_count' => 8, 'verdict' => 'even'],
            ['group' => 'company_distance', 'label' => '会社との距離', 'self_count' => 0, 'competitor_count' => 3, 'max_count' => 8, 'verdict' => 'competitor_advantage'],
            ['group' => 'job_appeal', 'label' => '仕事の魅力', 'self_count' => 4, 'competitor_count' => 1, 'max_count' => 8, 'verdict' => 'self_advantage'],
        ];

        $overview = $this->composer()->comparisonOverview(6, 24, 6, 24, $groupTotals);

        $this->assertContains('自社は6 / 24項目、競合は6 / 24項目の情報が確認できました。', $overview);
        $this->assertContains('「会社の魅力」は自社・競合とも同程度の情報量でした。', $overview);
        $this->assertContains('「会社との距離」は競合の情報量が自社を上回りました。', $overview);
        $this->assertContains('「仕事の魅力」は自社の情報量が競合を上回りました。', $overview);
    }
}
