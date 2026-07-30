<?php

namespace Tests\Unit\BrandWheel;

use App\Models\BrandWheelAnalysisResult;
use App\Services\BrandWheel\BrandWheelHexagonSvgBuilder;
use Tests\TestCase;

class BrandWheelHexagonSvgBuilderTest extends TestCase
{
    private BrandWheelHexagonSvgBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new BrandWheelHexagonSvgBuilder;
    }

    private function makeResult(array $axes, bool $coreValueReadable): BrandWheelAnalysisResult
    {
        return new BrandWheelAnalysisResult([
            'axes' => $axes,
            'core_value_readable' => $coreValueReadable,
        ]);
    }

    public function test_svg_has_the_exact_viewbox_and_dimensions(): void
    {
        $svg = $this->builder->build($this->makeResult([], false));

        $this->assertStringContainsString('viewBox="0 0 380 316"', $svg);
        $this->assertStringContainsString('width="380" height="316"', $svg);
    }

    public function test_it_is_not_a_radar_chart_it_uses_fixed_hexagon_segments_not_a_plotted_polygon(): void
    {
        $svg = $this->builder->build($this->makeResult([
            ['axis_key' => 'will_activity', 'state' => 'read'],
        ], false));

        // レーダーチャートの特徴(単一の多角形をpolygon/polylineで直接プロット
        // する)ではなく、6つの固定サイズの台形セグメント(path)で構成される
        // ことを確認する。
        $this->assertStringNotContainsString('<polygon', $svg);
        $this->assertStringNotContainsString('<polyline', $svg);
        $this->assertSame(6, substr_count($svg, '<path') - 1); // -1はCore Value中心の六角形
    }

    public function test_all_six_axes_get_a_name_and_state_text_label_regardless_of_color(): void
    {
        $svg = $this->builder->build($this->makeResult([
            ['axis_key' => 'will_activity', 'state' => 'read'],
            ['axis_key' => 'asset', 'state' => 'partial'],
        ], false));

        foreach (['活動的魅力', '資産的魅力', '経営スタイル', '就業環境', '情緒的便益', '金銭的便益'] as $nameJa) {
            $this->assertStringContainsString($nameJa, $svg);
        }
        // 色分けだけに頼らず、状態そのものをテキストラベルとして必ず持つ。
        $this->assertStringContainsString('読み取れました', $svg);
        $this->assertStringContainsString('一部読み取れました', $svg);
        $this->assertStringContainsString('読み取れませんでした', $svg);
    }

    public function test_missing_axis_result_defaults_to_unread(): void
    {
        // configには6軸あるが、resultには1軸しか無いケース(例: insufficient_input
        // ではない通常の未完了データ)でも、残り5軸はunread扱いになり例外にならない。
        $svg = $this->builder->build($this->makeResult([
            ['axis_key' => 'will_activity', 'state' => 'read'],
        ], false));

        $this->assertStringContainsString('#2a78d6', $svg);
        // unreadは輪郭色(#898781)のみでfillしない。
        $this->assertStringContainsString('#898781', $svg);
    }

    public function test_core_value_is_solid_when_readable_and_dashed_when_not(): void
    {
        $readableSvg = $this->builder->build($this->makeResult([], true));
        $unreadableSvg = $this->builder->build($this->makeResult([], false));

        $this->assertStringNotContainsString('stroke-dasharray', $readableSvg);
        $this->assertStringContainsString('stroke-dasharray="4 3"', $unreadableSvg);
        $this->assertStringContainsString('Core Value', $readableSvg);
    }

    public function test_it_never_uses_good_or_bad_colors(): void
    {
        $svg = $this->builder->build($this->makeResult([
            ['axis_key' => 'will_activity', 'state' => 'read'],
            ['axis_key' => 'asset', 'state' => 'unread'],
        ], false));

        // 緑・赤系の色コードを使わない(良し悪しの示唆を避ける)。
        $this->assertStringNotContainsString('green', strtolower($svg));
        $this->assertStringNotContainsString('red', strtolower($svg));
        $this->assertStringNotContainsString('#0f0', $svg);
        $this->assertStringNotContainsString('#f00', $svg);
    }

    public function test_japanese_labels_are_escaped_safely_for_xml(): void
    {
        // sub_elementsの日本語ラベルにXMLで特別な意味を持つ文字が
        // 万一含まれても、SVGとして不正にならないことを確認する。
        config(['brand_wheel.axes.will_activity.name_ja' => 'A&B<C>']);

        $svg = $this->builder->build($this->makeResult([
            ['axis_key' => 'will_activity', 'state' => 'read'],
        ], false));

        $this->assertStringContainsString('A&amp;B&lt;C&gt;', $svg);
        $this->assertStringNotContainsString('A&B<C>', $svg);
    }
}
