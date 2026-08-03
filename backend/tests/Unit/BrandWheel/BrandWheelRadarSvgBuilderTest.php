<?php

namespace Tests\Unit\BrandWheel;

use App\Services\BrandWheel\BrandWheelRadarSvgBuilder;
use Tests\TestCase;

class BrandWheelRadarSvgBuilderTest extends TestCase
{
    private BrandWheelRadarSvgBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new BrandWheelRadarSvgBuilder;
    }

    private function axis(string $key, string $name, int $matched, int $max): array
    {
        return ['key' => $key, 'name' => $name, 'matched_count' => $matched, 'max_count' => $max];
    }

    public function test_svg_has_the_exact_viewbox_matching_the_frontend_geometry(): void
    {
        $axes = [$this->axis('will_activity', '活動的魅力', 2, 4)];

        $svg = $this->builder->build($axes, null);

        $this->assertStringContainsString('viewBox="0 0 380 276"', $svg);
        $this->assertStringContainsString('width="380" height="276"', $svg);
    }

    public function test_ring_count_follows_max_count_not_a_fixed_constant(): void
    {
        // 3軸すべてmatched_count>0にして、自社の面(polygon)が確実に
        // 描かれる状態にする(3点未満だと面自体を描かないため)。
        $fourAxes = [
            $this->axis('will_activity', '活動的魅力', 1, 4),
            $this->axis('asset', '資産的魅力', 1, 4),
            $this->axis('personality', '経営スタイル', 1, 4),
        ];
        $fiveAxes = [
            $this->axis('will_activity', '活動的魅力', 1, 5),
            $this->axis('asset', '資産的魅力', 1, 5),
            $this->axis('personality', '経営スタイル', 1, 5),
        ];

        $svgFour = $this->builder->build($fourAxes, null);
        $svgFive = $this->builder->build($fiveAxes, null);

        // グリッドの目盛りリングはmax_count分(<polygon>で描画)。
        // 系列本体(自社1本)も<polygon>を1つ使うため、その分を引く。
        $this->assertSame(4, substr_count($svgFour, '<polygon') - 1);
        $this->assertSame(5, substr_count($svgFive, '<polygon') - 1);
    }

    public function test_only_the_self_series_is_drawn_when_competitor_is_null(): void
    {
        $axes = [
            $this->axis('will_activity', '活動的魅力', 3, 4),
            $this->axis('asset', '資産的魅力', 2, 4),
            $this->axis('personality', '経営スタイル', 1, 4),
        ];

        $svg = $this->builder->build($axes, null);

        $this->assertStringContainsString('#2a78d6', $svg);
        $this->assertStringNotContainsString('#eb6834', $svg);
    }

    public function test_both_series_are_drawn_when_competitor_axes_are_given(): void
    {
        $selfAxes = [
            $this->axis('will_activity', '活動的魅力', 3, 4),
            $this->axis('asset', '資産的魅力', 2, 4),
            $this->axis('personality', '経営スタイル', 1, 4),
        ];
        $competitorAxes = [
            $this->axis('will_activity', '活動的魅力', 4, 4),
            $this->axis('asset', '資産的魅力', 4, 4),
            $this->axis('personality', '経営スタイル', 2, 4),
        ];

        $svg = $this->builder->build($selfAxes, $competitorAxes);

        $this->assertStringContainsString('#2a78d6', $svg);
        $this->assertStringContainsString('#eb6834', $svg);
        $this->assertStringContainsString('fill-opacity="0.16"', $svg);
        // 点は白フチ付き。
        $this->assertStringContainsString('stroke="#ffffff" stroke-width="1.4"', $svg);
    }

    /**
     * max_countが0の軸は0として描かず(中心に落とさず)点を打たない
     * ―― 「読み取れなかった」を「0点」として扱わない既存方針。
     */
    public function test_an_axis_with_zero_max_count_is_not_plotted_as_a_point_at_the_center(): void
    {
        $axes = [
            $this->axis('will_activity', '活動的魅力', 3, 4),
            $this->axis('asset', '資産的魅力', 2, 4),
            $this->axis('personality', '経営スタイル', 0, 0),
            $this->axis('relationship', '就業環境', 1, 4),
        ];

        $svg = $this->builder->build($axes, null);

        // 中心(190,138)に点が打たれていないこと。
        $this->assertStringNotContainsString('cx="190.00" cy="138.00"', $svg);
    }

    public function test_returns_no_series_polygon_when_fewer_than_three_points_can_be_plotted(): void
    {
        $axes = [
            $this->axis('will_activity', '活動的魅力', 1, 4),
            $this->axis('asset', '資産的魅力', 0, 0),
            $this->axis('personality', '経営スタイル', 0, 0),
        ];

        $svg = $this->builder->build($axes, null);

        $this->assertStringNotContainsString('#2a78d6', $svg);
    }

    public function test_matches_the_first_axis_point_computed_by_the_same_formula_as_the_frontend(): void
    {
        // wheelPoint(0, 4, 1) = angle -90度、CX=190,CY=138,R=100
        // → (190 + 100*cos(-90°), 138 + 100*sin(-90°)) = (190.00, 38.00)
        $axes = [
            $this->axis('will_activity', '活動的魅力', 4, 4),
            $this->axis('asset', '資産的魅力', 4, 4),
            $this->axis('personality', '経営スタイル', 4, 4),
            $this->axis('relationship', '就業環境', 4, 4),
        ];

        $svg = $this->builder->build($axes, null);

        $this->assertStringContainsString('190.00,38.00', $svg);
    }

    public function test_japanese_axis_labels_are_escaped_safely_for_xml(): void
    {
        $axes = [
            $this->axis('will_activity', 'A&B<C>', 1, 4),
            $this->axis('asset', '資産的魅力', 1, 4),
            $this->axis('personality', '経営スタイル', 1, 4),
        ];

        $svg = $this->builder->build($axes, null);

        $this->assertStringContainsString('A&amp;B&lt;C&gt;', $svg);
        $this->assertStringNotContainsString('A&B<C>', $svg);
    }

    public function test_returns_an_empty_frame_without_error_when_axes_are_empty(): void
    {
        $svg = $this->builder->build([], null);

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
    }
}
