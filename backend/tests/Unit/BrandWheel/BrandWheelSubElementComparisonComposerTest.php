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
     * @return list<array{key: string, group: string, name: string, matched_sub_elements: list<array{key: string, name: string}>}>
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
     * a(競合にあり自社に無い)+b(自社にあり競合に無い)+c(どちらにも無い)+
     * 共通(両方に該当)=24になることを検算する
     * (docs/lead-report-layout/README.md「A+B+C+共通=24になることを検算する」)。
     */
    public function test_split_by_gap_accounts_for_all_twenty_four_items_including_the_common_remainder(): void
    {
        $selfAxes = $this->axesWithMatches(['will_activity' => ['purpose', 'business_expansion'], 'asset' => ['brand_recognition']]);
        $competitorAxes = $this->axesWithMatches(['will_activity' => ['purpose'], 'personality' => ['leadership']]);

        $items = $this->composer->compose($selfAxes, $competitorAxes);
        $split = $this->composer->splitByGap($items);

        $common = count(array_filter($items, fn (array $i) => $i['self_matched'] && $i['competitor_matched']));

        $this->assertCount(24, $items);
        $this->assertSame(24, count($split['a']) + count($split['b']) + count($split['c']) + $common);

        // 具体的な内訳も確認する: purposeは両方に該当するので共通(a/b/cいずれにも含まれない)。
        $this->assertFalse(collect($split['a'])->contains('sub_key', 'purpose'));
        $this->assertFalse(collect($split['b'])->contains('sub_key', 'purpose'));
        // leadershipは競合のみ該当 → a。
        $this->assertTrue(collect($split['a'])->contains('sub_key', 'leadership'));
        // business_expansionは自社のみ該当 → b。
        $this->assertTrue(collect($split['b'])->contains('sub_key', 'business_expansion'));
    }
}
