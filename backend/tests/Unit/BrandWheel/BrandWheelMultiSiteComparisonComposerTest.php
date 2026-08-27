<?php

namespace Tests\Unit\BrandWheel;

use App\Services\BrandWheel\BrandWheelMultiSiteComparisonComposer;
use Tests\TestCase;

/**
 * 依頼AC-1: 多社比較(自社1×競合N社、N=3〜5)の中核ロジック。
 * 過半数の境界値(特にN=4→3)・2つの抽出セット・決定的な並び順・
 * 代表競合の選定を固定する。
 */
class BrandWheelMultiSiteComparisonComposerTest extends TestCase
{
    private BrandWheelMultiSiteComparisonComposer $composer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->composer = new BrandWheelMultiSiteComparisonComposer;
    }

    /**
     * @return list<array{key: string, group: string, name: string, matched_sub_elements: list<array{key: string, name: string}>, label_only_sub_elements: list<array{key: string, name: string}>}>
     */
    private function axesWithMatches(array $matchedByAxisKey): array
    {
        $axesConfig = (array) config('brand_wheel.axes', []);
        $axes = [];

        foreach ($axesConfig as $axisKey => $definition) {
            $matchedKeys = $matchedByAxisKey[$axisKey] ?? [];
            $subElements = (array) $definition['sub_elements'];

            $axes[] = [
                'key' => $axisKey,
                'group' => $definition['group'],
                'name' => $definition['name_ja'],
                'matched_sub_elements' => array_values(array_map(
                    fn (string $k) => ['key' => $k, 'name' => $subElements[$k]],
                    $matchedKeys,
                )),
                'label_only_sub_elements' => [],
            ];
        }

        return $axes;
    }

    // ------------------------------------------------------------------
    // 過半数(floor(N/2)+1)の境界値。N=4→3が最重要(境界)。
    // ------------------------------------------------------------------

    public function test_majority_threshold_matches_the_floor_n_over_2_plus_1_table(): void
    {
        $this->assertSame(2, $this->composer->majorityThreshold(3));
        $this->assertSame(3, $this->composer->majorityThreshold(4));
        $this->assertSame(3, $this->composer->majorityThreshold(5));
    }

    public function test_compose_marks_is_majority_true_only_at_or_above_the_threshold_for_four_competitors(): void
    {
        $selfAxes = $this->axesWithMatches([]);
        // 4社中3社がpurposeに該当(過半数=3ちょうど) → is_majority=true。
        $threeOfFour = [
            $this->axesWithMatches(['will_activity' => ['purpose']]),
            $this->axesWithMatches(['will_activity' => ['purpose']]),
            $this->axesWithMatches(['will_activity' => ['purpose']]),
            $this->axesWithMatches([]),
        ];
        // 4社中2社のみ該当(過半数未満) → is_majority=false。
        $twoOfFour = [
            $this->axesWithMatches(['will_activity' => ['business_expansion']]),
            $this->axesWithMatches(['will_activity' => ['business_expansion']]),
            $this->axesWithMatches([]),
            $this->axesWithMatches([]),
        ];

        $itemsThree = $this->composer->compose($selfAxes, $threeOfFour);
        $itemsTwo = $this->composer->compose($selfAxes, $twoOfFour);

        $purpose = collect($itemsThree)->firstWhere('sub_key', 'purpose');
        $this->assertSame(3, $purpose['competitor_matched_count']);
        $this->assertTrue($purpose['is_majority']);

        $businessExpansion = collect($itemsTwo)->firstWhere('sub_key', 'business_expansion');
        $this->assertSame(2, $businessExpansion['competitor_matched_count']);
        $this->assertFalse($businessExpansion['is_majority']);
    }

    public function test_competitor_matched_flags_length_equals_competitor_count(): void
    {
        $competitorAxesList = [
            $this->axesWithMatches([]),
            $this->axesWithMatches([]),
            $this->axesWithMatches([]),
        ];

        $items = $this->composer->compose($this->axesWithMatches([]), $competitorAxesList);

        $this->assertCount(3, $items[0]['competitor_matched_flags']);
    }

    // ------------------------------------------------------------------
    // ①自社に足りない項目・②自社の強み
    // ------------------------------------------------------------------

    public function test_extract_missing_from_self_selects_items_self_lacks_where_competitors_reach_majority(): void
    {
        // N=5、過半数=3。
        $selfAxes = $this->axesWithMatches([]); // 自社はどれも非該当
        $competitorAxesList = [
            $this->axesWithMatches(['will_activity' => ['purpose', 'business_expansion']]),
            $this->axesWithMatches(['will_activity' => ['purpose', 'business_expansion']]),
            $this->axesWithMatches(['will_activity' => ['purpose']]),
            $this->axesWithMatches([]),
            $this->axesWithMatches([]),
        ];
        // purpose: 3/5社該当(過半数以上) → 抽出対象。
        // business_expansion: 2/5社該当(過半数未満) → 対象外。

        $items = $this->composer->compose($selfAxes, $competitorAxesList);
        $missing = $this->composer->extractMissingFromSelf($items);

        $missingKeys = array_column($missing, 'sub_key');
        $this->assertContains('purpose', $missingKeys);
        $this->assertNotContains('business_expansion', $missingKeys);
    }

    public function test_extract_missing_from_self_excludes_items_self_already_has(): void
    {
        $selfAxes = $this->axesWithMatches(['will_activity' => ['purpose']]);
        $competitorAxesList = [
            $this->axesWithMatches(['will_activity' => ['purpose']]),
            $this->axesWithMatches(['will_activity' => ['purpose']]),
            $this->axesWithMatches(['will_activity' => ['purpose']]),
        ];

        $items = $this->composer->compose($selfAxes, $competitorAxesList);
        $missing = $this->composer->extractMissingFromSelf($items);

        // 自社もpurposeを持っているため「足りない項目」には出ない。
        $this->assertNotContains('purpose', array_column($missing, 'sub_key'));
    }

    public function test_extract_self_strengths_selects_items_self_has_where_competitors_are_below_majority(): void
    {
        // N=3、過半数=2。
        $selfAxes = $this->axesWithMatches(['will_activity' => ['purpose', 'business_expansion']]);
        $competitorAxesList = [
            $this->axesWithMatches([]),
            $this->axesWithMatches(['will_activity' => ['business_expansion']]),
            $this->axesWithMatches([]),
        ];
        // purpose: 自社○、競合0/3 → 強み。
        // business_expansion: 自社○、競合1/3(過半数未満) → 強み。

        $items = $this->composer->compose($selfAxes, $competitorAxesList);
        $strengths = $this->composer->extractSelfStrengths($items);

        $strengthKeys = array_column($strengths, 'sub_key');
        $this->assertContains('purpose', $strengthKeys);
        $this->assertContains('business_expansion', $strengthKeys);
    }

    public function test_extract_self_strengths_excludes_items_where_competitors_reach_majority(): void
    {
        $selfAxes = $this->axesWithMatches(['will_activity' => ['purpose']]);
        $competitorAxesList = [
            $this->axesWithMatches(['will_activity' => ['purpose']]),
            $this->axesWithMatches(['will_activity' => ['purpose']]),
            $this->axesWithMatches([]),
        ];
        // purpose: 自社○、競合2/3(過半数=2以上) → 強みではない。

        $items = $this->composer->compose($selfAxes, $competitorAxesList);
        $strengths = $this->composer->extractSelfStrengths($items);

        $this->assertNotContains('purpose', array_column($strengths, 'sub_key'));
    }

    public function test_extraction_handles_zero_matching_items_without_error(): void
    {
        $selfAxes = $this->axesWithMatches([]);
        $competitorAxesList = [$this->axesWithMatches([]), $this->axesWithMatches([]), $this->axesWithMatches([])];

        $items = $this->composer->compose($selfAxes, $competitorAxesList);

        $this->assertSame([], $this->composer->extractMissingFromSelf($items));
        $this->assertSame([], $this->composer->extractSelfStrengths($items));
    }

    // ------------------------------------------------------------------
    // 並び順の決定性(件数降順、同数はconfig順)
    // ------------------------------------------------------------------

    public function test_extract_missing_from_self_sorts_by_competitor_count_descending(): void
    {
        $selfAxes = $this->axesWithMatches([]);
        // purpose: 3/5、business_expansion: 5/5、project_initiative: 4/5。
        $competitorAxesList = [
            $this->axesWithMatches(['will_activity' => ['purpose', 'business_expansion', 'project_initiative']]),
            $this->axesWithMatches(['will_activity' => ['purpose', 'business_expansion', 'project_initiative']]),
            $this->axesWithMatches(['will_activity' => ['purpose', 'business_expansion', 'project_initiative']]),
            $this->axesWithMatches(['will_activity' => ['business_expansion', 'project_initiative']]),
            $this->axesWithMatches(['will_activity' => ['business_expansion']]),
        ];

        $items = $this->composer->compose($selfAxes, $competitorAxesList);
        $missing = $this->composer->extractMissingFromSelf($items);
        $missingKeys = array_column($missing, 'sub_key');

        $this->assertSame(
            ['business_expansion', 'project_initiative', 'purpose'],
            array_values(array_intersect($missingKeys, ['business_expansion', 'project_initiative', 'purpose'])),
        );
    }

    /**
     * 同数の場合、config('brand_wheel.axes')の並び順(=compose()の元の並び)を
     * そのまま保つ ―― PHP8+のusort()の安定ソート保証に依存する。
     */
    public function test_ties_are_broken_by_config_definition_order(): void
    {
        // purposeとbusiness_expansionを同数(3/5)の過半数該当にする
        // (config順ではpurposeが先)。
        $selfAxes = $this->axesWithMatches([]);
        $competitorAxesList = [
            $this->axesWithMatches(['will_activity' => ['purpose', 'business_expansion']]),
            $this->axesWithMatches(['will_activity' => ['purpose', 'business_expansion']]),
            $this->axesWithMatches(['will_activity' => ['purpose', 'business_expansion']]),
            $this->axesWithMatches([]),
            $this->axesWithMatches([]),
        ];

        $items = $this->composer->compose($selfAxes, $competitorAxesList);
        $missing = $this->composer->extractMissingFromSelf($items);
        $missingKeys = array_column($missing, 'sub_key');

        $purposeIndex = array_search('purpose', $missingKeys, true);
        $businessExpansionIndex = array_search('business_expansion', $missingKeys, true);

        $this->assertNotFalse($purposeIndex);
        $this->assertNotFalse($businessExpansionIndex);
        $this->assertLessThan($businessExpansionIndex, $purposeIndex);
    }

    /**
     * 同じ入力を何度並び替えても常に同じ順序になること(依頼AC-1、
     * 「同じ入力なら常に同じ順序」の直接的な確認)。
     */
    public function test_same_input_always_produces_the_same_order(): void
    {
        $selfAxes = $this->axesWithMatches([]);
        $competitorAxesList = [
            $this->axesWithMatches(['will_activity' => ['purpose'], 'asset' => ['brand_recognition', 'competitiveness']]),
            $this->axesWithMatches(['will_activity' => ['purpose'], 'asset' => ['brand_recognition']]),
            $this->axesWithMatches(['asset' => ['brand_recognition']]),
        ];

        $items = $this->composer->compose($selfAxes, $competitorAxesList);

        $first = array_column($this->composer->extractMissingFromSelf($items), 'sub_key');
        $second = array_column($this->composer->extractMissingFromSelf($items), 'sub_key');
        $third = array_column($this->composer->extractMissingFromSelf($items), 'sub_key');

        $this->assertSame($first, $second);
        $this->assertSame($second, $third);
    }

    // ------------------------------------------------------------------
    // 代表競合の選定(display_orderが最も早い、該当する1社)
    // ------------------------------------------------------------------

    public function test_representative_competitor_index_returns_the_earliest_matching_index(): void
    {
        $this->assertSame(1, $this->composer->representativeCompetitorIndex([false, true, true]));
        $this->assertSame(0, $this->composer->representativeCompetitorIndex([true, false, true]));
    }

    public function test_representative_competitor_index_returns_null_when_nobody_matches(): void
    {
        $this->assertNull($this->composer->representativeCompetitorIndex([false, false, false]));
    }
}
