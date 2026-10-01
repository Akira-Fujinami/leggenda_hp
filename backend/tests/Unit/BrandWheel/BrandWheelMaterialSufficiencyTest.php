<?php

namespace Tests\Unit\BrandWheel;

use App\Services\BrandWheel\BrandWheelMaterialSufficiency;
use Tests\TestCase;

/**
 * 依頼CH-1b(2026-10-01)。
 */
class BrandWheelMaterialSufficiencyTest extends TestCase
{
    public function test_returns_true_when_input_char_count_is_null(): void
    {
        // 旧データ(遡及計算しない、依頼者指定)・判定がsuccessに到達
        // しなかった場合。「材料不足」と断定しない(安全側)。
        $this->assertTrue((new BrandWheelMaterialSufficiency)->isSufficient(null));
    }

    public function test_returns_false_when_below_the_configured_threshold(): void
    {
        config(['brand_wheel.insufficient_material_display_min_chars' => 3000]);

        $this->assertFalse((new BrandWheelMaterialSufficiency)->isSufficient(2334));
    }

    public function test_returns_true_when_exactly_at_the_configured_threshold(): void
    {
        config(['brand_wheel.insufficient_material_display_min_chars' => 3000]);

        $this->assertTrue((new BrandWheelMaterialSufficiency)->isSufficient(3000));
    }

    public function test_returns_true_when_above_the_configured_threshold(): void
    {
        config(['brand_wheel.insufficient_material_display_min_chars' => 3000]);

        $this->assertTrue((new BrandWheelMaterialSufficiency)->isSufficient(35752));
    }
}
