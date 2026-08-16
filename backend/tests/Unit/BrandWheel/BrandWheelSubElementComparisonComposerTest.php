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
