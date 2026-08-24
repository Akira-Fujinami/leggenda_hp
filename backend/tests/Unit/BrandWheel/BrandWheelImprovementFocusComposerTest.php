<?php

namespace Tests\Unit\BrandWheel;

use App\Services\BrandWheel\BrandWheelImprovementFocusComposer;
use Tests\TestCase;

class BrandWheelImprovementFocusComposerTest extends TestCase
{
    private BrandWheelImprovementFocusComposer $composer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->composer = new BrandWheelImprovementFocusComposer;
    }

    private function item(string $axisKey, string $group, string $subKey, bool $selfMatched, bool $competitorMatched): array
    {
        return [
            'axis_key' => $axisKey,
            'axis_name' => $axisKey.'_name',
            'group' => $group,
            'sub_key' => $subKey,
            'sub_name' => $subKey.'_name',
            'definition' => $subKey.'の定義文。',
            'recommendation' => $subKey.'の行動文。',
            'self_matched' => $selfMatched,
            'competitor_matched' => $competitorMatched,
        ];
    }

    public function test_returns_null_when_there_are_no_comparison_items(): void
    {
        $this->assertNull($this->composer->compose([], []));
    }

    /**
     * config('brand_wheel.group_labels')の並び順は
     * company_appeal → company_distance → job_appeal(config/brand_wheel.php参照)。
     * 3グループとも(競合-自社)の差が同じ(1)場合、並び順で最初のグループを選ぶ。
     */
    public function test_ties_between_groups_are_broken_by_config_order(): void
    {
        $items = [
            $this->item('will_activity', 'company_appeal', 'purpose', false, true),
            $this->item('personality', 'company_distance', 'core_values', false, true),
            $this->item('emotional_benefit', 'job_appeal', 'pride', false, true),
        ];

        $result = $this->composer->compose($items, []);

        $this->assertSame('company_appeal', $result['selected_group']);
    }

    /**
     * 同点でなければ、差(競合件数-自社件数)が最大のグループを選ぶ
     * (config順で先頭ではない場合でも正しく選べることを確認する)。
     */
    public function test_selects_the_group_with_the_largest_competitor_minus_self_gap(): void
    {
        $items = [
            // company_appeal: gap = 1-1 = 0
            $this->item('will_activity', 'company_appeal', 'purpose', true, true),
            // company_distance: gap = 2-0 = 2 (最大)
            $this->item('personality', 'company_distance', 'core_values', false, true),
            $this->item('relationship', 'company_distance', 'colleagues', false, true),
            // job_appeal: gap = 1-0 = 1
            $this->item('emotional_benefit', 'job_appeal', 'pride', false, true),
        ];

        $result = $this->composer->compose($items, []);

        $this->assertSame('company_distance', $result['selected_group']);
    }

    public function test_groups_array_always_reports_all_three_groups_with_correct_counts(): void
    {
        $items = [
            $this->item('will_activity', 'company_appeal', 'purpose', true, true),
            $this->item('asset', 'company_appeal', 'brand_recognition', false, true),
            $this->item('personality', 'company_distance', 'core_values', false, false),
        ];

        $result = $this->composer->compose($items, []);
        $byGroup = collect($result['groups'])->keyBy('group');

        $this->assertCount(3, $result['groups']);
        $this->assertSame(1, $byGroup['company_appeal']['self_count']);
        $this->assertSame(2, $byGroup['company_appeal']['competitor_count']);
        $this->assertSame(2, $byGroup['company_appeal']['max_count']);
        $this->assertSame(0, $byGroup['company_distance']['self_count']);
        $this->assertSame(0, $byGroup['company_distance']['competitor_count']);
        // job_appealは1件も無いが、それでもグループとしては必ず出る。
        $this->assertSame(0, $byGroup['job_appeal']['max_count']);
    }

    /**
     * 選ばれたグループの中で「競合にあり自社に無い」項目のみを、
     * 渡した配列の並び順(=config順)で先頭から最大3件選ぶ。
     */
    public function test_selects_up_to_three_competitor_only_items_from_the_selected_group_in_order(): void
    {
        $items = [
            $this->item('personality', 'company_distance', 'leadership', false, true),
            $this->item('personality', 'company_distance', 'org_structure', false, true),
            $this->item('personality', 'company_distance', 'company_character', true, true), // 両方該当、候補から除外
            $this->item('personality', 'company_distance', 'core_values', false, true),
            $this->item('relationship', 'company_distance', 'colleagues', false, true), // 4件目、3件を超えるため選ばれない
        ];

        $result = $this->composer->compose($items, []);

        $this->assertSame('company_distance', $result['selected_group']);
        $this->assertCount(3, $result['items']);
        $this->assertSame(['leadership_name', 'org_structure_name', 'core_values_name'], array_column($result['items'], 'sub_name'));
    }

    public function test_returns_fewer_than_three_items_without_padding_from_other_groups(): void
    {
        $items = [
            $this->item('personality', 'company_distance', 'leadership', false, true),
        ];

        $result = $this->composer->compose($items, []);

        $this->assertCount(1, $result['items']);
    }

    public function test_attaches_competitor_evidence_for_selected_items_and_null_when_missing(): void
    {
        $items = [
            $this->item('personality', 'company_distance', 'leadership', false, true),
            $this->item('personality', 'company_distance', 'org_structure', false, true),
        ];

        $result = $this->composer->compose($items, [
            'personality' => ['leadership' => '経営陣は現場から意見を吸い上げています。'],
        ]);

        $byName = collect($result['items'])->keyBy('sub_name');
        $this->assertSame('経営陣は現場から意見を吸い上げています。', $byName['leadership_name']['competitor_evidence']);
        $this->assertNull($byName['org_structure_name']['competitor_evidence']);
    }

    public function test_items_include_the_definition_text_for_use_as_the_candidate_description(): void
    {
        $items = [
            $this->item('personality', 'company_distance', 'leadership', false, true),
        ];

        $result = $this->composer->compose($items, []);

        $this->assertSame('leadershipの定義文。', $result['items'][0]['definition']);
    }

    /**
     * 修正4(2026-08-25): 競合引用(60字上限)も、上限内に収まる最後の句点
     * (。)で切る(文の途中で切らない)。
     */
    public function test_competitor_evidence_is_truncated_at_the_last_sentence_boundary_within_the_limit(): void
    {
        $firstSentence = str_repeat('あ', 20).'。';
        $secondSentence = str_repeat('い', 50).'。';

        $items = [
            $this->item('personality', 'company_distance', 'leadership', false, true),
        ];

        $result = $this->composer->compose($items, [
            'personality' => ['leadership' => $firstSentence.$secondSentence],
        ]);

        $this->assertSame($firstSentence, $result['items'][0]['competitor_evidence']);
        $this->assertStringEndsWith('。', $result['items'][0]['competitor_evidence']);
    }
}
