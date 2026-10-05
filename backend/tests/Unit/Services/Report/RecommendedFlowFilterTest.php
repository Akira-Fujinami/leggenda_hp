<?php

namespace Tests\Unit\Services\Report;

use App\Services\Report\RecommendedFlowFilter;
use Tests\TestCase;

/**
 * 依頼CN-A3(2026-10-06): 点線の枝(追加を検討したい導線)から、実線の枝(メニューの項目)に
 * 同じ主題があるものを除く。
 */
class RecommendedFlowFilterTest extends TestCase
{
    private function filter(array $flows, array $labels): array
    {
        return (new RecommendedFlowFilter)->filter($flows, $labels);
    }

    public function test_a_flow_whose_subject_is_in_the_menu_is_not_shown(): void
    {
        $flows = [
            ['name' => 'カルチャー・社風', 'option' => 'culture'],
            ['name' => '福利厚生', 'option' => 'benefits'],
        ];

        $this->assertSame(['福利厚生'], $this->filter($flows, ['カルチャー', 'サービス']));
    }

    public function test_matching_ignores_case_width_and_spaces(): void
    {
        $flows = [['name' => 'カルチャー・社風', 'option' => 'culture']];

        $this->assertSame([], $this->filter($flows, ['ＣＵＬＴＵＲＥ']));
        $this->assertSame([], $this->filter($flows, [' Culture ']));
        $this->assertSame(['カルチャー・社風'], $this->filter($flows, ['Services']));
    }

    public function test_labels_folded_out_of_view_are_included_by_the_caller_and_matched(): void
    {
        $allLabels = ['事業を知る', '職場', '数字で見る', '社員', 'カルチャー'];

        $this->assertSame([], $this->filter([['name' => 'カルチャー・社風', 'option' => 'culture']], $allLabels));
    }

    public function test_a_flow_without_a_survey_option_is_matched_by_its_own_name_only(): void
    {
        $flows = [
            ['name' => '数字で見る', 'option' => null],
            ['name' => 'サステナビリティ', 'option' => null],
        ];

        $this->assertSame(['サステナビリティ'], $this->filter($flows, ['数字で見るMoneyForward']));
    }

    public function test_it_returns_nothing_when_every_flow_is_covered(): void
    {
        $this->assertSame([], $this->filter([['name' => '社員を知る', 'option' => 'employee_interview']], ['インタビュー']));
    }

    public function test_every_survey_option_has_menu_words_in_config(): void
    {
        $words = (array) config('admin_comparison_pptx.site_hierarchy_flow_menu_words');
        $options = array_keys((array) config('brand_wheel_candidate_survey.options'));

        foreach ($options as $key) {
            $this->assertNotEmpty($words[$key] ?? [], "調査の選択肢 {$key} のメニューの言葉がconfigにある");
        }
        $this->assertSame([], array_diff(array_keys($words), $options), 'configに無い調査の選択肢のキーを持たない');
    }
}
