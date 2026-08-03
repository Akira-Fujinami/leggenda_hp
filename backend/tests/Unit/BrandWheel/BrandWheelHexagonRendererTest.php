<?php

namespace Tests\Unit\BrandWheel;

use App\Services\BrandWheel\BrandWheelHexagonRenderer;
use Tests\TestCase;

class BrandWheelHexagonRendererTest extends TestCase
{
    private BrandWheelHexagonRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new BrandWheelHexagonRenderer;
    }

    public function test_it_rasterizes_a_valid_svg_to_a_non_transparent_png_at_2x_resolution(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 380 316" width="380" height="316">'
            .'<rect width="380" height="316" fill="#fcfcfb" /><text x="10" y="30" font-family="IPAexGothic, sans-serif">活動的魅力</text>'
            .'</svg>';

        $png = $this->renderer->renderPng($svg);

        $this->assertNotNull($png);
        // PNGマジックバイト。
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));

        // IHDRチャンクから幅・高さを読み取り、2倍解像度(760x632)であることを確認する。
        $width = unpack('N', substr($png, 16, 4))[1];
        $height = unpack('N', substr($png, 20, 4))[1];
        $this->assertSame(760, $width);
        $this->assertSame(632, $height);

        // 目安として200KB以下(ヘキサゴン程度のシンプルな図形であれば十分収まる)。
        $this->assertLessThanOrEqual(200 * 1024, strlen($png));
    }

    public function test_it_returns_null_without_throwing_when_rsvg_convert_fails(): void
    {
        $png = $this->renderer->renderPng('this is not valid svg at all <<<');

        $this->assertNull($png);
    }

    /**
     * 2026-08-04: レーダー図(380x276、ヘキサゴンとはアスペクト比が異なる)を
     * 同じラスタライズ経路で扱うため、幅・高さを引数で上書きできる。
     */
    public function test_it_rasterizes_at_a_custom_size_when_width_and_height_are_given(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 380 276" width="380" height="276">'
            .'<rect width="380" height="276" fill="#ffffff" />'
            .'</svg>';

        $png = $this->renderer->renderPng($svg, 760, 552);

        $this->assertNotNull($png);
        $width = unpack('N', substr($png, 16, 4))[1];
        $height = unpack('N', substr($png, 20, 4))[1];
        $this->assertSame(760, $width);
        $this->assertSame(552, $height);
    }
}
