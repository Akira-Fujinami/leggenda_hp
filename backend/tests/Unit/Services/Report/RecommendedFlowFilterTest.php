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

    // ---- 依頼CO-5: 広すぎる言葉を絞る ----

    public function test_removed_broad_words_no_longer_match_unrelated_menus(): void
    {
        $cases = [
            ['福利厚生', 'benefits', '評価制度'],
            ['福利厚生', 'benefits', '研修制度'],
            ['社員を知る', 'employee_interview', '社員数'],
            ['社員を知る', 'employee_interview', 'People & Culture'],
            ['働き方を知る', 'work_environment', '環境への取り組み'],
            ['トップメッセージ', 'executive_interview', 'トップページ'],
            ['トップメッセージ', 'executive_interview', '経営理念'],
            ['数字で見る働き方', 'overtime_leave_data', 'データ事業'],
            ['研修・教育', 'training', '開発部門'],
            ['研修・教育', 'training', '教育事業'],
            ['仕事を知る', 'job_content', '業務提携'],
            ['仕事を知る', 'job_content', '募集要項'],
            ['キャリアパス', 'career_path', '事業成長'],
            ['社内イベント', 'company_event', '交流'],
            ['プロジェクト事例', 'achievements_projects', 'ケーススタディ'],
        ];
        foreach ($cases as [$name, $option, $label]) {
            $this->assertSame([$name], $this->filter([['name' => $name, 'option' => $option]], [$label]), "「{$label}」では「{$name}」が除かれない");
        }
    }

    public function test_added_words_match(): void
    {
        $this->assertSame([], $this->filter([['name' => 'トップメッセージ', 'option' => 'executive_interview']], ['代表メッセージ']));
        $this->assertSame([], $this->filter([['name' => 'トップメッセージ', 'option' => 'executive_interview']], ['役員紹介']));
        $this->assertSame([], $this->filter([['name' => 'トップメッセージ', 'option' => 'executive_interview']], ['トップメッセージ']));
        $this->assertSame([], $this->filter([['name' => '数字で見る働き方', 'option' => 'overtime_leave_data']], ['数字で見る']));
    }

    /** 英字の言葉は語の境界で照合する(jobはjobsに当たるが、historyのstoryには当たらない)。 */
    public function test_english_words_match_on_word_boundaries(): void
    {
        $matches = [
            ['仕事を知る', 'job_content', 'Jobs'],
            ['社員を知る', 'employee_interview', 'Our Stories'],
            ['社員を知る', 'employee_interview', 'Employee Story'],
            ['カルチャー・社風', 'culture', 'Money Forward Culture Deck'],
            ['社内イベント', 'company_event', 'Events'],
            ['働き方を知る', 'work_environment', 'Work Style'],
        ];
        foreach ($matches as [$name, $option, $label]) {
            $this->assertSame([], $this->filter([['name' => $name, 'option' => $option]], [$label]), "「{$label}」は「{$name}」に当たる");
        }

        $nonMatches = [
            ['社員を知る', 'employee_interview', 'Company History'],
            ['カルチャー・社風', 'culture', 'Agriculture'],
            ['社内イベント', 'company_event', 'Prevention'],
            ['事業を知る', 'business_service', 'Production'],
            ['仕事を知る', 'job_content', 'Jobless'],
        ];
        foreach ($nonMatches as [$name, $option, $label]) {
            $this->assertSame([$name], $this->filter([['name' => $name, 'option' => $option]], [$label]), "「{$label}」は「{$name}」に当たらない");
        }
    }

    public function test_the_removed_words_are_not_in_the_config(): void
    {
        $words = (array) config('admin_comparison_pptx.site_hierarchy_flow_menu_words');
        $removed = [
            'job_content' => ['業務', '募集'], 'career_path' => ['成長'],
            'employee_interview' => ['社員', 'メンバー', 'member', 'people'],
            'work_environment' => ['環境', 'environment'], 'executive_interview' => ['トップ', '経営'],
            'overtime_leave_data' => ['データ', 'data'], 'benefits' => ['制度', '休暇'],
            'company_event' => ['交流'], 'training' => ['教育', 'development', 'education'],
            'achievements_projects' => ['case'],
        ];
        foreach ($removed as $option => $list) {
            foreach ($list as $word) {
                $this->assertNotContains($word, $words[$option], "{$option}から「{$word}」を外した");
            }
        }
    }
}
