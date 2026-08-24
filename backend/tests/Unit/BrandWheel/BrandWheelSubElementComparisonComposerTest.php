<?php

namespace Tests\Unit\BrandWheel;

use App\Services\BrandWheel\BrandWheelSubElementComparisonComposer;
use Tests\TestCase;

class BrandWheelSubElementComparisonComposerTest extends TestCase
{
    private BrandWheelSubElementComparisonComposer $composer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->composer = new BrandWheelSubElementComparisonComposer;
    }

    /**
     * @return list<array{key: string, group: string, name: string, matched_sub_elements: list<array{key: string, name: string}>, label_only_sub_elements: list<array{key: string, name: string}>}>
     */
    private function axesWithMatches(array $matchedByAxisKey, array $labelOnlyByAxisKey = []): array
    {
        $axesConfig = (array) config('brand_wheel.axes', []);
        $axes = [];

        foreach ($axesConfig as $axisKey => $definition) {
            $matchedKeys = $matchedByAxisKey[$axisKey] ?? [];
            $labelOnlyKeys = $labelOnlyByAxisKey[$axisKey] ?? [];
            $subElements = (array) $definition['sub_elements'];

            $axes[] = [
                'key' => $axisKey,
                'group' => $definition['group'],
                'name' => $definition['name_ja'],
                'matched_sub_elements' => array_values(array_map(
                    fn (string $k) => ['key' => $k, 'name' => $subElements[$k]],
                    $matchedKeys,
                )),
                'label_only_sub_elements' => array_values(array_map(
                    fn (string $k) => ['key' => $k, 'name' => $subElements[$k]],
                    $labelOnlyKeys,
                )),
            ];
        }

        return $axes;
    }

    /**
     * config('brand_wheel.axes')の並び順(軸→軸内sub_elementsの並び)が
     * そのまま項目順になっていること ―― 表示順をコードに直書きしない、
     * という設計方針の直接的な確認。
     */
    public function test_composes_all_twenty_four_items_in_config_order(): void
    {
        $items = $this->composer->compose($this->axesWithMatches([]), $this->axesWithMatches([]));

        $this->assertCount(24, $items);
        $this->assertSame('will_activity', $items[0]['axis_key']);
        $this->assertSame('purpose', $items[0]['sub_key']);
        $this->assertSame('financial_benefit', $items[23]['axis_key']);
        $this->assertSame('employment_stability', $items[23]['sub_key']);
    }

    public function test_self_matched_and_competitor_matched_reflect_each_sides_matched_sub_elements_independently(): void
    {
        $selfAxes = $this->axesWithMatches(['will_activity' => ['purpose']]);
        $competitorAxes = $this->axesWithMatches(['will_activity' => ['purpose', 'business_expansion']]);

        $items = $this->composer->compose($selfAxes, $competitorAxes);
        $byKey = collect($items)->keyBy('sub_key');

        $this->assertTrue($byKey['purpose']['self_matched']);
        $this->assertTrue($byKey['purpose']['competitor_matched']);
        $this->assertFalse($byKey['business_expansion']['self_matched']);
        $this->assertTrue($byKey['business_expansion']['competitor_matched']);
        $this->assertFalse($byKey['project_initiative']['self_matched']);
        $this->assertFalse($byKey['project_initiative']['competitor_matched']);
    }

    /**
     * 競合axesが空(status!=='success'または競合なし)の場合、
     * competitor_matchedは常にfalseになる ―― 例外を投げない。
     */
    public function test_empty_competitor_axes_results_in_all_competitor_matched_false(): void
    {
        $items = $this->composer->compose($this->axesWithMatches(['will_activity' => ['purpose']]), []);

        $this->assertTrue(collect($items)->firstWhere('sub_key', 'purpose')['self_matched']);
        $this->assertFalse(collect($items)->contains('competitor_matched', true));
    }

    public function test_definition_text_comes_from_config_sub_element_definitions(): void
    {
        $items = $this->composer->compose($this->axesWithMatches([]), $this->axesWithMatches([]));

        $orgStructure = collect($items)->firstWhere('sub_key', 'org_structure');
        $this->assertSame(
            (string) config('brand_wheel.axes.personality.sub_element_definitions.org_structure'),
            $orgStructure['definition'],
        );
        $this->assertNotSame('', $orgStructure['definition']);
    }

    /**
     * 2026-08-25追加(修正: 所見→提案): 改善提案カード表示専用の行動文
     * (sub_element_recommendations)。判定用のsub_element_definitionsとは
     * 別のconfigキーから引く。
     */
    public function test_recommendation_text_comes_from_config_sub_element_recommendations(): void
    {
        $items = $this->composer->compose($this->axesWithMatches([]), $this->axesWithMatches([]));

        $orgStructure = collect($items)->firstWhere('sub_key', 'org_structure');
        $this->assertSame(
            (string) config('brand_wheel.axes.personality.sub_element_recommendations.org_structure'),
            $orgStructure['recommendation'],
        );
        $this->assertNotSame('', $orgStructure['recommendation']);
        // definitionとrecommendationは別テキスト(取り違えて同じキーを
        // 参照していないことの確認)。
        $this->assertNotSame($orgStructure['definition'], $orgStructure['recommendation']);
    }

    /**
     * 24項目すべてに行動文が定義されていること(欠けがないことを機械的に
     * 検証する ―― config('brand_wheel.axes')に新しい下位要素が追加された
     * 場合、sub_element_recommendationsの追加漏れをここで検知する)。
     */
    public function test_all_24_items_have_a_non_empty_recommendation_text(): void
    {
        $items = $this->composer->compose($this->axesWithMatches([]), $this->axesWithMatches([]));

        $this->assertCount(24, $items);
        foreach ($items as $item) {
            $this->assertNotSame(
                '',
                $item['recommendation'],
                "missing sub_element_recommendations for {$item['axis_key']}.{$item['sub_key']}",
            );
        }
    }

    /**
     * 2026-08-25追加: sub_element_definitions(判定用、AIプロンプトで使用)は
     * 今回のカード表示変更(所見→提案)で変更していないことを確認する。
     * ここを変えるとAIの判定基準が変わってしまうため、行動文
     * (sub_element_recommendations)とは完全に別のキーとして追加した。
     */
    public function test_sub_element_definitions_are_unchanged_by_the_recommendation_text_addition(): void
    {
        $expected = [
            'will_activity' => [
                'purpose' => '会社が何を目指しているか、存在意義や志を述べた記述。取扱商品・サービス内容の説明のみでは該当しない。',
                'business_expansion' => '具体的な事業領域・商品・サービスの内容についての記述。',
                'project_initiative' => '新規プロジェクトや新しい取り組みの具体的な紹介。既存事業の説明のみでは該当しない。',
                'social_contribution' => '地域貢献・CSR等、社会に対する貢献活動についての記述。',
            ],
            'asset' => [
                'brand_recognition' => '会社や実績が外部からどう評価・認知されているかについての記述。',
                'competitiveness' => '他社にはない強みや独自の技術・ポジションについての記述。',
                'scale_influence' => '売上高・拠点数・従業員数など、事業規模や業界内での影響力を示す記述。',
                'office_facility' => 'オフィス環境や設備についての具体的な記述。',
            ],
            'personality' => [
                'leadership' => '経営者・幹部の考え方や意思決定スタイルについての記述。',
                'org_structure' => '部門・チームの編成、階層、意思決定の通り方についての記述。部署名や役職名の列挙のみでは該当しない。',
                'company_character' => '会社全体としての気質・社風を述べた記述。個人の心構えや意気込みの表明のみでは該当しない。',
                'core_values' => '組織として大切にしている価値観や行動指針についての記述。',
            ],
            'relationship' => [
                'colleagues' => '同僚や先輩がどのような人物かについての具体的な記述。',
                'atmosphere' => '職場の雰囲気や社内の空気感についての記述。',
                'physical_freedom' => 'リモートワーク・フレックス等、働く場所や時間の裁量についての記述。',
                'mental_freedom' => '意見の言いやすさや裁量の大きさなど、心理的な自由度についての記述。',
            ],
            'emotional_benefit' => [
                'pride' => 'その仕事に就くことで誇りを持てるという記述。',
                'talkable' => '他人に話したくなる、自慢したくなるという記述。',
                'satisfaction' => '仕事を通じて得られる満足感や充実感についての記述。',
                'superiority' => '他と比べて優れている、選ばれた立場にあるという感覚についての記述。',
            ],
            'financial_benefit' => [
                'salary_level' => '給与の水準についての具体的な記述。',
                'benefits' => '福利厚生制度についての具体的な記述。',
                'growth_opportunity' => 'スキルアップやキャリア形成の機会についての記述。',
                'employment_stability' => '雇用の継続性や経営の安定性についての記述。',
            ],
        ];

        foreach ($expected as $axisKey => $subElements) {
            foreach ($subElements as $subKey => $definition) {
                $this->assertSame(
                    $definition,
                    (string) config("brand_wheel.axes.{$axisKey}.sub_element_definitions.{$subKey}"),
                    "sub_element_definitions.{$axisKey}.{$subKey} changed unexpectedly",
                );
            }
        }
    }

    /**
     * 2026-08-08: ○△－の3値化。self_state/competitor_stateは
     * 'matched'(○)|'label_only'(△)|'none'(－)のいずれかを返す
     * (self_matched/competitor_matchedは○のみtrueのまま、改善提案の
     * 選定ロジック用に意味・値とも変更しない)。判定はこのクラス(プログラム側)
     * のみが行い、AIには一切3段階を判定させない(ユーザー指摘)。
     */
    public function test_self_state_and_competitor_state_reflect_matched_label_only_and_none_independently(): void
    {
        $selfAxes = $this->axesWithMatches(
            ['will_activity' => ['purpose']],
            ['will_activity' => ['business_expansion']],
        );
        $competitorAxes = $this->axesWithMatches(
            [],
            ['will_activity' => ['purpose']],
        );

        $items = $this->composer->compose($selfAxes, $competitorAxes);
        $byKey = collect($items)->keyBy('sub_key');

        // purpose: 自社は○(matched)、競合は△(label_only)。
        $this->assertSame('matched', $byKey['purpose']['self_state']);
        $this->assertTrue($byKey['purpose']['self_matched']);
        $this->assertSame('label_only', $byKey['purpose']['competitor_state']);
        $this->assertFalse($byKey['purpose']['competitor_matched']);

        // business_expansion: 自社は△(label_only)、競合は－(none)。
        $this->assertSame('label_only', $byKey['business_expansion']['self_state']);
        $this->assertFalse($byKey['business_expansion']['self_matched']);
        $this->assertSame('none', $byKey['business_expansion']['competitor_state']);

        // project_initiative: どちらにも該当しないため－(none)。
        $this->assertSame('none', $byKey['project_initiative']['self_state']);
        $this->assertSame('none', $byKey['project_initiative']['competitor_state']);
    }

    public function test_empty_label_only_sub_elements_results_in_all_items_being_matched_or_none(): void
    {
        $items = $this->composer->compose(
            $this->axesWithMatches(['will_activity' => ['purpose']]),
            $this->axesWithMatches([]),
        );

        $states = array_unique(array_column($items, 'self_state'));
        sort($states);
        $this->assertSame(['matched', 'none'], $states);
    }

    // ------------------------------------------------------------------
    // 2026-08-17追加: groupTotals()(「○△－の対比表」ページの比較サマリー・
    // グループ優劣バッジ用)。
    // ------------------------------------------------------------------

    public function test_group_totals_returns_all_three_groups_with_self_and_competitor_counts(): void
    {
        $items = $this->composer->compose($this->axesWithMatches([]), $this->axesWithMatches([]));

        $totals = $this->composer->groupTotals($items);

        $this->assertCount(3, $totals);
        $this->assertSame(['company_appeal', 'company_distance', 'job_appeal'], array_column($totals, 'group'));
    }

    /**
     * config('brand_wheel.group_advantage_diff_min')(既定2)以上の差が
     * あれば優位/劣位、未満なら'even'と判定する。
     */
    public function test_group_totals_verdict_reflects_the_configured_advantage_threshold(): void
    {
        $selfAxes = $this->axesWithMatches(['relationship' => ['colleagues', 'atmosphere']]);
        $competitorAxes = $this->axesWithMatches([]);
        $items = $this->composer->compose($selfAxes, $competitorAxes);

        $totals = $this->composer->groupTotals($items);
        $companyDistance = collect($totals)->firstWhere('group', 'company_distance');

        $this->assertSame(2, $companyDistance['self_count']);
        $this->assertSame(0, $companyDistance['competitor_count']);
        $this->assertSame('self_advantage', $companyDistance['verdict']);
    }

    public function test_group_totals_verdict_is_even_when_the_difference_is_below_the_threshold(): void
    {
        $selfAxes = $this->axesWithMatches(['will_activity' => ['purpose']]);
        $competitorAxes = $this->axesWithMatches([]);
        $items = $this->composer->compose($selfAxes, $competitorAxes);

        $totals = $this->composer->groupTotals($items);
        $companyAppeal = collect($totals)->firstWhere('group', 'company_appeal');

        // 差は1件のみ(既定閾値2未満)のため'even'。
        $this->assertSame('even', $companyAppeal['verdict']);
    }
}
