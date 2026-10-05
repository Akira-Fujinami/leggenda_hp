<?php

namespace Tests\Unit\Services\Report;

use App\Services\Report\CandidateSurveyCatalog;
use Tests\TestCase;

/**
 * 依頼CL-2(2026-10-05): 調査の選択肢(options、14件)を24項目の対応表(mapping)から
 * 独立して持つようにした。対応づけの中身は見直していない(依頼者が確定版を返したら
 * configの差し替えだけで反映できる)ため、移行前と同じ結果になることをここで固定する。
 */
class CandidateSurveyCatalogTest extends TestCase
{
    /**
     * 依頼CB-2当時(移行前)の、24項目 => [調査の項目名, 割合]。移行後の
     * optionForSubElement()が、これと完全に同じ名前・割合を返すこと。
     *
     * @return array<string, array{0: ?string, 1: ?float}>
     */
    public static function legacyMapping(): array
    {
        return [
            'will_activity.purpose' => ['企業のビジョンや理念', 16.0],
            'will_activity.business_expansion' => ['企業の事業・サービス', 17.0],
            'will_activity.project_initiative' => ['実績・支援事例・プロジェクト', 6.2],
            'will_activity.social_contribution' => [null, null],
            'asset.brand_recognition' => ['実績・支援事例・プロジェクト', 6.2],
            'asset.competitiveness' => ['企業の事業・サービス', 17.0],
            'asset.scale_influence' => [null, null],
            'asset.office_facility' => ['働き方や職場環境', 17.0],
            'personality.leadership' => ['代表・経営層のインタビュー', 13.4],
            'personality.org_structure' => [null, null],
            'personality.company_character' => ['カルチャー・社風', 11.4],
            'personality.core_values' => ['企業のビジョンや理念', 16.0],
            'relationship.colleagues' => ['社員インタビュー', 19.4],
            // 依頼CP-1(2026-10-06): 職場の雰囲気は「会社のイベント」ではなく「カルチャー・社風」に対応させた。
            'relationship.atmosphere' => ['カルチャー・社風', 11.4],
            'relationship.physical_freedom' => ['残業時間や有給取得の客観データ', 12.6],
            'relationship.mental_freedom' => ['働き方や職場環境', 17.0],
            'emotional_benefit.pride' => ['社員インタビュー', 19.4],
            'emotional_benefit.talkable' => ['社員インタビュー', 19.4],
            // 依頼CP-1: 満足感は調査の選択肢に対応させない(仕事内容そのものを見る項目は24項目に無い)。
            'emotional_benefit.satisfaction' => [null, null],
            'emotional_benefit.superiority' => [null, null],
            'financial_benefit.salary_level' => ['給与体系や評価制度', 16.4],
            'financial_benefit.benefits' => ['福利厚生', 12.4],
            'financial_benefit.growth_opportunity' => ['実現できるキャリアパス', 23.8],
            'financial_benefit.employment_stability' => [null, null],
        ];
    }

    private function catalog(): CandidateSurveyCatalog
    {
        return new CandidateSurveyCatalog;
    }

    public function test_options_are_fourteen_and_sorted_by_percentage_descending_with_config_order_for_ties(): void
    {
        $options = $this->catalog()->options();

        $this->assertCount(14, $options);
        $percentages = array_column($options, 'percentage');
        $sorted = $percentages;
        rsort($sorted);
        $this->assertSame($sorted, $percentages);

        // 17.0%が2件(企業の事業・サービス／働き方や職場環境)。同率はconfigの記載順。
        $keys = array_column($options, 'key');
        $this->assertLessThan(array_search('work_environment', $keys, true), array_search('business_service', $keys, true));
        $this->assertSame('job_content', $keys[0]);
        $this->assertSame('achievements_projects', $keys[13]);
    }

    public function test_the_survey_names_and_percentages_live_only_in_options_and_mapping_only_references_keys(): void
    {
        $options = (array) config('brand_wheel_candidate_survey.options');

        foreach ((array) config('brand_wheel_candidate_survey.mapping') as $axisKey => $subs) {
            foreach ($subs as $subKey => $entry) {
                $this->assertArrayNotHasKey('survey_item', $entry, "{$axisKey}.{$subKey}: 名前を重複して持たない");
                $this->assertArrayNotHasKey('percentage', $entry, "{$axisKey}.{$subKey}: 割合を重複して持たない");
                if ($entry['survey_option'] !== null) {
                    $this->assertArrayHasKey($entry['survey_option'], $options, "{$axisKey}.{$subKey}: 一覧に無いキーを参照していない");
                }
            }
        }
    }

    public function test_the_mapping_covers_all_24_sub_elements(): void
    {
        $count = 0;
        foreach ((array) config('brand_wheel.axes') as $axisKey => $axisConfig) {
            foreach (array_keys($axisConfig['sub_elements']) as $subKey) {
                $this->assertTrue(config()->has("brand_wheel_candidate_survey.mapping.{$axisKey}.{$subKey}"), "{$axisKey}.{$subKey}の対応がある");
                $count++;
            }
        }
        $this->assertSame(24, $count);
    }

    /**
     * 既存の「足りないもの」の各行の調査の文が、変更前と同じ結果になること。
     */
    public function test_every_sub_element_resolves_to_the_same_survey_item_and_percentage_as_before_the_migration(): void
    {
        $catalog = $this->catalog();

        foreach (self::legacyMapping() as $path => [$expectedName, $expectedPercentage]) {
            [$axisKey, $subKey] = explode('.', $path);
            $option = $catalog->optionForSubElement($axisKey, $subKey);

            if ($expectedName === null) {
                $this->assertNull($option, "{$path}: 該当なしはnullのまま");

                continue;
            }

            $this->assertSame($expectedName, $option['name'], "{$path}: 調査の項目名");
            $this->assertSame($expectedPercentage, $option['percentage'], "{$path}: 割合");
        }
    }

    public function test_sub_elements_for_an_option_follow_the_axis_order_and_an_unmapped_option_has_none(): void
    {
        $catalog = $this->catalog();

        $this->assertSame([
            ['axis_key' => 'relationship', 'sub_key' => 'colleagues'],
            ['axis_key' => 'emotional_benefit', 'sub_key' => 'pride'],
            ['axis_key' => 'emotional_benefit', 'sub_key' => 'talkable'],
        ], $catalog->subElementsForOption('employee_interview'));

        // 研修制度(7.4%)はどの項目にも対応していない。
        $this->assertSame([], $catalog->subElementsForOption('training'));
        $this->assertSame('研修制度', config('brand_wheel_candidate_survey.options.training.name'));
    }

    public function test_an_option_key_that_does_not_exist_resolves_to_null_instead_of_failing(): void
    {
        config(['brand_wheel_candidate_survey.mapping.will_activity.purpose.survey_option' => 'no_such_option']);

        $this->assertNull($this->catalog()->optionForSubElement('will_activity', 'purpose'));
    }
}
