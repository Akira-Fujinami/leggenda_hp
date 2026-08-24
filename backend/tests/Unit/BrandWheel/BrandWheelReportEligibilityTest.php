<?php

namespace Tests\Unit\BrandWheel;

use App\Models\BrandWheelAnalysisResult;
use App\Services\BrandWheel\BrandWheelReportEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-08-24追加。「白紙のレポートを出さない」の中核となる単一の判定を、
 * GenerateBrandWheelAnalysisJob/LeadAnalysisController経由ではなく直接検証する。
 */
class BrandWheelReportEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private BrandWheelReportEligibility $eligibility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eligibility = new BrandWheelReportEligibility;
    }

    public function test_null_result_is_not_reportable(): void
    {
        $this->assertFalse($this->eligibility->isReportable(null));
    }

    public function test_error_status_is_not_reportable(): void
    {
        $result = BrandWheelAnalysisResult::factory()->create(['status' => 'error', 'axes' => null]);

        $this->assertFalse($this->eligibility->isReportable($result));
    }

    public function test_insufficient_input_status_is_not_reportable(): void
    {
        $result = BrandWheelAnalysisResult::factory()->create(['status' => 'insufficient_input', 'axes' => null]);

        $this->assertFalse($this->eligibility->isReportable($result));
    }

    public function test_success_with_zero_matched_sub_elements_is_not_reportable(): void
    {
        $result = BrandWheelAnalysisResult::factory()->create([
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => []],
                ['axis_key' => 'asset', 'matched_sub_elements' => []],
            ],
        ]);

        $this->assertFalse($this->eligibility->isReportable($result));
    }

    public function test_success_with_at_least_one_matched_sub_element_is_reportable(): void
    {
        $result = BrandWheelAnalysisResult::factory()->create([
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]],
                ['axis_key' => 'asset', 'matched_sub_elements' => []],
            ],
        ]);

        $this->assertTrue($this->eligibility->isReportable($result));
    }
}
