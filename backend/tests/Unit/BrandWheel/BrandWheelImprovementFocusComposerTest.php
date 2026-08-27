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

    // ------------------------------------------------------------------
    // 依頼X(2026-08-26、レポート42): 自社が3領域すべてで競合を上回るとき、
    // 件数差だけで領域を選ぶと候補(競合にあり自社に無い項目)が0件の領域が
    // 選ばれてしまう不具合の修正。
    // ------------------------------------------------------------------

    /**
     * レポート42相当の入力。3領域とも差(競合-自社)は同じ-2だが、候補項目が
     * あるのはcompany_distanceの1件(精神的自由度)のみ。差だけで選ぶと同点の
     * 走査順でcompany_appealが選ばれてしまうが、候補がある領域に限定すると
     * company_distanceが選ばれる。
     *
     * 依頼AH-1(2026-08-28): ①(mental_freedom)だけでは3件に満たないため、
     * ②(競合にも自社にも無い項目、a7/a8)が$comparisonItemsの並び順で
     * 補われ、合計3件になる ―― ②はselectedGroupKey(company_distance)に
     * 限定されない(a7/a8はcompany_appeal所属)。
     */
    public function test_selects_the_group_with_a_candidate_even_when_its_gap_is_not_the_largest(): void
    {
        $items = [
            // company_appeal: self=6/8, competitor=4/8, candidate=0
            //   (competitorがmatchした4件は、すべてselfもmatchしている)
            $this->item('a1', 'company_appeal', 'a1', true, true),
            $this->item('a2', 'company_appeal', 'a2', true, true),
            $this->item('a3', 'company_appeal', 'a3', true, true),
            $this->item('a4', 'company_appeal', 'a4', true, true),
            $this->item('a5', 'company_appeal', 'a5', true, false),
            $this->item('a6', 'company_appeal', 'a6', true, false),
            $this->item('a7', 'company_appeal', 'a7', false, false),
            $this->item('a8', 'company_appeal', 'a8', false, false),
            // company_distance: self=6/8, competitor=4/8, candidate=1(精神的自由度)
            $this->item('d1', 'company_distance', 'd1', true, true),
            $this->item('d2', 'company_distance', 'd2', true, true),
            $this->item('d3', 'company_distance', 'd3', true, true),
            $this->item('d4', 'company_distance', 'mental_freedom', false, true),
            $this->item('d5', 'company_distance', 'd5', true, false),
            $this->item('d6', 'company_distance', 'd6', true, false),
            $this->item('d7', 'company_distance', 'd7', false, false),
            // job_appeal: self=4/8, competitor=2/8, candidate=0
            $this->item('j1', 'job_appeal', 'j1', true, true),
            $this->item('j2', 'job_appeal', 'j2', true, true),
            $this->item('j3', 'job_appeal', 'j3', true, false),
            $this->item('j4', 'job_appeal', 'j4', true, false),
            $this->item('j5', 'job_appeal', 'j5', false, false),
            $this->item('j6', 'job_appeal', 'j6', false, false),
            $this->item('j7', 'job_appeal', 'j7', false, false),
            $this->item('j8', 'job_appeal', 'j8', false, false),
        ];

        $result = $this->composer->compose($items, []);

        $this->assertSame('company_distance', $result['selected_group']);
        $this->assertCount(3, $result['items']);
        $this->assertSame('mental_freedom_name', $result['items'][0]['sub_name']);
        $this->assertSame('catch_up', $result['items'][0]['type']);
        $this->assertSame(['a7_name', 'a8_name'], [$result['items'][1]['sub_name'], $result['items'][2]['sub_name']]);
        $this->assertSame('breakout', $result['items'][1]['type']);
        $this->assertSame('breakout', $result['items'][2]['type']);
    }

    /**
     * 選ばれた領域の差(競合-自社)が1以上のときは、従来どおりgap_positive
     * テンプレートを使う(領域名・件数を含む)。
     */
    public function test_lead_text_uses_the_gap_positive_template_when_the_selected_group_gap_is_positive(): void
    {
        $items = [
            $this->item('personality', 'company_distance', 'leadership', false, true),
            $this->item('personality', 'company_distance', 'org_structure', false, true),
        ];

        $result = $this->composer->compose($items, []);

        $this->assertSame(
            sprintf((string) config('brand_wheel.improvement_focus_templates.gap_positive'), '会社との距離', 2),
            $result['lead_text'],
        );
    }

    /**
     * レポート42相当(選ばれた領域の差が0以下、候補は1件以上ある)のとき、
     * 「差が最も大きかった」という事実に反する主張を避けるため、
     * gap_non_positiveテンプレート(領域名・差の大小に触れない)を使う。
     */
    public function test_lead_text_uses_the_gap_non_positive_template_when_the_selected_group_gap_is_zero_or_negative(): void
    {
        $items = [
            $this->item('d1', 'company_distance', 'd1', true, true),
            $this->item('d2', 'company_distance', 'd2', true, true),
            $this->item('d3', 'company_distance', 'd3', true, true),
            $this->item('d4', 'company_distance', 'mental_freedom', false, true),
            $this->item('d5', 'company_distance', 'd5', true, false),
            $this->item('d6', 'company_distance', 'd6', true, false),
            // 依頼AH-1: self=trueにして②(競合にも自社にも無い項目)候補から
            // 除外する ―― ①だけの純粋なケースを検証したいテストのため、
            // d7がbreakoutとして補われて2件になるのを防ぐ。
            $this->item('d7', 'company_distance', 'd7', true, false),
            $this->item('a1', 'company_appeal', 'a1', true, true),
            $this->item('j1', 'job_appeal', 'j1', true, true),
        ];

        $result = $this->composer->compose($items, []);

        $this->assertSame('company_distance', $result['selected_group']);
        $this->assertSame(
            sprintf((string) config('brand_wheel.improvement_focus_templates.gap_non_positive'), 1),
            $result['lead_text'],
        );
    }

    /**
     * 依頼X-1/X-2: どの領域にも候補項目(①)が無い場合(自社が全領域で競合以上)、
     * nullではなくitems=[]の非nullを返す(呼び出し側でページを消さない
     * 判断に使う)。candidate_count(group)===0は数学的にself_count(group)
     * >=competitor_count(group)を含意するため、no_candidate_self_ahead
     * テンプレートが使われる。
     *
     * 依頼AH-1(2026-08-28): items=[]になるのは①②とも0件のときのみ
     * (=自社の全項目が○のとき、クラスdocblockの数学的根拠を参照)。
     * この入力は自社が全項目self_matched=trueのため、②(!competitor_matched
     * && !self_matched)の候補も存在せず、依頼Xの挙動は変わらない。
     */
    public function test_returns_a_non_null_result_with_empty_items_when_no_group_has_a_candidate(): void
    {
        $items = [
            // どのグループも「競合にあり自社に無い」項目(①)が無く、自社が
            // 全項目matchedのため②(競合にも自社にも無い項目)も無い。
            $this->item('a1', 'company_appeal', 'a1', true, true),
            $this->item('a2', 'company_appeal', 'a2', true, false),
            $this->item('d1', 'company_distance', 'd1', true, true),
            $this->item('j1', 'job_appeal', 'j1', true, true),
            $this->item('j2', 'job_appeal', 'j2', true, false),
        ];

        $result = $this->composer->compose($items, []);

        $this->assertNotNull($result);
        $this->assertSame([], $result['items']);
        $this->assertSame(
            (string) config('brand_wheel.improvement_focus_templates.no_candidate_self_ahead'),
            $result['lead_text'],
        );
        // 「0件挙げます」のような件数を含む文言(gap_positive/gap_non_positive)は
        // 使われないこと。
        $this->assertStringNotContainsString('0件', $result['lead_text']);
    }

    // ------------------------------------------------------------------
    // 依頼AH-1(2026-08-28、レポート50): 改善提案のカードを2種類にする。
    // ①「追いつく」(competitor_matched && !self_matched、無改修)に加え、
    // ②「抜け出す」(!competitor_matched && !self_matched)で不足分を補う。
    // ------------------------------------------------------------------

    /**
     * ①が3件以上あるとき、②は出さない(合計の最大枚数は3枚のまま)。
     */
    public function test_breakout_items_are_not_added_when_catch_up_alone_already_fills_three_slots(): void
    {
        $items = [
            $this->item('personality', 'company_distance', 'leadership', false, true), // ①
            $this->item('personality', 'company_distance', 'org_structure', false, true), // ①
            $this->item('personality', 'company_distance', 'company_character', false, true), // ①
            $this->item('personality', 'company_distance', 'core_values', false, false), // ②候補(選ばれないはず)
        ];

        $result = $this->composer->compose($items, []);

        $this->assertCount(3, $result['items']);
        $this->assertSame(['catch_up', 'catch_up', 'catch_up'], array_column($result['items'], 'type'));
        $this->assertNotContains('core_values_name', array_column($result['items'], 'sub_name'));
    }

    /**
     * レポート50相当: ①が1件のとき、②で2枚補われ、合計3枚になる。
     * ②はselectedGroupKeyに限定されない(自社が伝えていない項目すべてが
     * 供給源になる、依頼者指定の狙い)。
     */
    public function test_breakout_items_fill_the_remaining_slots_when_catch_up_has_only_one_item(): void
    {
        $items = [
            $this->item('personality', 'company_distance', 'leadership', false, true), // ①(1件)
            $this->item('personality', 'company_distance', 'org_structure', true, true), // 両方該当、対象外
            $this->item('will_activity', 'company_appeal', 'purpose', false, false), // ②候補
            $this->item('emotional_benefit', 'job_appeal', 'pride', false, false), // ②候補
            $this->item('emotional_benefit', 'job_appeal', 'talkable', false, false), // ②候補(4件目、選ばれない)
        ];

        $result = $this->composer->compose($items, []);

        $this->assertCount(3, $result['items']);
        $this->assertSame('catch_up', $result['items'][0]['type']);
        $this->assertSame('leadership_name', $result['items'][0]['sub_name']);
        $this->assertSame('breakout', $result['items'][1]['type']);
        $this->assertSame('purpose_name', $result['items'][1]['sub_name']);
        $this->assertSame('breakout', $result['items'][2]['type']);
        $this->assertSame('pride_name', $result['items'][2]['sub_name']);
    }

    /**
     * ①が0件で②が3件以上あるとき、②だけで3枚になる。
     */
    public function test_breakout_items_alone_fill_all_three_slots_when_there_are_no_catch_up_items(): void
    {
        $items = [
            $this->item('will_activity', 'company_appeal', 'purpose', false, false),
            $this->item('will_activity', 'company_appeal', 'business_expansion', false, false),
            $this->item('personality', 'company_distance', 'leadership', false, false),
            $this->item('personality', 'company_distance', 'org_structure', true, false), // 自社matched、対象外
        ];

        $result = $this->composer->compose($items, []);

        $this->assertCount(3, $result['items']);
        $this->assertSame(['breakout', 'breakout', 'breakout'], array_column($result['items'], 'type'));
        $this->assertSame(['purpose_name', 'business_expansion_name', 'leadership_name'], array_column($result['items'], 'sub_name'));
    }

    /**
     * ②のcompetitor_evidenceは常にnull(比較サイトにも記述が無いため引用が
     * 存在しない)。①は従来どおりevidenceが付く。
     */
    public function test_breakout_items_never_have_competitor_evidence(): void
    {
        $items = [
            $this->item('personality', 'company_distance', 'leadership', false, true),
            $this->item('will_activity', 'company_appeal', 'purpose', false, false),
        ];

        $result = $this->composer->compose($items, [
            'personality' => ['leadership' => '経営陣は現場から意見を吸い上げています。'],
        ]);

        $byName = collect($result['items'])->keyBy('sub_name');
        $this->assertSame('経営陣は現場から意見を吸い上げています。', $byName['leadership_name']['competitor_evidence']);
        $this->assertNull($byName['purpose_name']['competitor_evidence']);
    }

    /**
     * ②の並び順は$comparisonItemsの並び順(=config('brand_wheel.axes')の
     * 並び順、sub_element_definitionsの定義順と同じ)を使う ―― 同じ入力なら
     * 必ず同じ順序になる決定的な規則であることを、渡す順序を変えても結果が
     * 変わらないことで確認する。
     */
    public function test_breakout_item_order_is_deterministic_and_follows_the_comparison_items_order(): void
    {
        $items = [
            $this->item('will_activity', 'company_appeal', 'purpose', false, false),
            $this->item('personality', 'company_distance', 'leadership', false, false),
            $this->item('emotional_benefit', 'job_appeal', 'pride', false, false),
        ];

        $result1 = $this->composer->compose($items, []);
        $result2 = $this->composer->compose($items, []);

        $expectedOrder = ['purpose_name', 'leadership_name', 'pride_name'];
        $this->assertSame($expectedOrder, array_column($result1['items'], 'sub_name'));
        $this->assertSame($expectedOrder, array_column($result2['items'], 'sub_name'));
    }

    /**
     * ②を1件でも含む場合、①のみを前提にした「比較サイトの記述にあり」
     * 「この領域から」という主張(gap_positive/gap_non_positive)は使わず、
     * 位置・出典を主張しないitems_include_breakoutを使う。
     */
    public function test_lead_text_uses_the_breakout_template_when_items_include_a_breakout_item(): void
    {
        $items = [
            $this->item('personality', 'company_distance', 'leadership', false, true), // ①
            $this->item('will_activity', 'company_appeal', 'purpose', false, false), // ②
        ];

        $result = $this->composer->compose($items, []);

        $this->assertSame(
            sprintf((string) config('brand_wheel.improvement_focus_templates.items_include_breakout'), 2),
            $result['lead_text'],
        );
        $this->assertStringNotContainsString('比較サイトの記述にあり', $result['lead_text']);
    }

    /**
     * ①が0件で②のみのときも、items_include_breakoutを使う(gap_positive/
     * gap_non_positiveは使わない)。
     */
    public function test_lead_text_uses_the_breakout_template_when_there_are_only_breakout_items(): void
    {
        $items = [
            $this->item('will_activity', 'company_appeal', 'purpose', false, false),
        ];

        $result = $this->composer->compose($items, []);

        $this->assertSame(
            sprintf((string) config('brand_wheel.improvement_focus_templates.items_include_breakout'), 1),
            $result['lead_text'],
        );
    }
}
