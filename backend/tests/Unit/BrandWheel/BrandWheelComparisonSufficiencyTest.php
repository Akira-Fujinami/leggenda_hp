<?php

namespace Tests\Unit\BrandWheel;

use App\Services\BrandWheel\BrandWheelComparisonSufficiency;
use Tests\TestCase;

class BrandWheelComparisonSufficiencyTest extends TestCase
{
    public function test_is_sufficient_when_total_matched_meets_the_configured_threshold(): void
    {
        config(['brand_wheel.comparison_sufficiency_threshold' => 6]);

        $this->assertTrue((new BrandWheelComparisonSufficiency)->isSufficient(6));
        $this->assertTrue((new BrandWheelComparisonSufficiency)->isSufficient(7));
    }

    public function test_is_not_sufficient_when_total_matched_is_below_the_configured_threshold(): void
    {
        config(['brand_wheel.comparison_sufficiency_threshold' => 6]);

        $this->assertFalse((new BrandWheelComparisonSufficiency)->isSufficient(5));
        $this->assertFalse((new BrandWheelComparisonSufficiency)->isSufficient(0));
    }

    public function test_reflects_a_config_override(): void
    {
        config(['brand_wheel.comparison_sufficiency_threshold' => 10]);

        $this->assertFalse((new BrandWheelComparisonSufficiency)->isSufficient(9));
        $this->assertTrue((new BrandWheelComparisonSufficiency)->isSufficient(10));
    }
}
