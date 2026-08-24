<?php

namespace Tests\Unit\BrandWheel;

use App\Models\BrandWheelAnalysisResult;
use App\Services\BrandWheel\BrandWheelReportEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-08-24追加、2026-08-25に閾値を引き上げ。「白紙のレポートを出さない」
 * 「顧客提出可能な品質に満たないレポートを出さない」の中核となる単一の
 * 判定を、GenerateBrandWheelAnalysisJob/LeadAnalysisController経由ではなく
 * 直接検証する。
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

    /**
     * @return list<array{axis_key: string, matched_sub_elements: list<array{key: string, evidence: string}>}>
     */
    private function axesWithMatchedCount(int $count): array
    {
        return [[
            'axis_key' => 'will_activity',
            'matched_sub_elements' => $count > 0
                ? array_map(fn (int $i) => ['key' => "sub_{$i}", 'evidence' => "evidence_{$i}"], range(1, $count))
                : [],
        ]];
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

    /**
     * 2026-08-25更新: 「1件以上」から引き上げ(依頼A、2026-08-24発行の
     * レポート33が自社1/24のまま顧客へ出力された事象への対応)。
     * 境界値5件(false)/6件(true)/7件(true)を検証する
     * (config('brand_wheel.report_eligibility_min_matched')の既定値6)。
     */
    public function test_success_with_five_matched_sub_elements_is_not_reportable(): void
    {
        $result = BrandWheelAnalysisResult::factory()->create([
            'status' => 'success',
            'axes' => $this->axesWithMatchedCount(5),
        ]);

        $this->assertFalse($this->eligibility->isReportable($result));
    }

    public function test_success_with_six_matched_sub_elements_is_reportable(): void
    {
        $result = BrandWheelAnalysisResult::factory()->create([
            'status' => 'success',
            'axes' => $this->axesWithMatchedCount(6),
        ]);

        $this->assertTrue($this->eligibility->isReportable($result));
    }

    public function test_success_with_seven_matched_sub_elements_is_reportable(): void
    {
        $result = BrandWheelAnalysisResult::factory()->create([
            'status' => 'success',
            'axes' => $this->axesWithMatchedCount(7),
        ]);

        $this->assertTrue($this->eligibility->isReportable($result));
    }

    /**
     * config('brand_wheel.report_eligibility_min_matched')を差し替えたとき、
     * 判定がそれに追随すること(閾値をこのクラス以外にハードコードしない
     * ための確認)。
     */
    public function test_respects_a_config_override_of_the_minimum_matched_threshold(): void
    {
        $result = BrandWheelAnalysisResult::factory()->create([
            'status' => 'success',
            'axes' => $this->axesWithMatchedCount(3),
        ]);

        config(['brand_wheel.report_eligibility_min_matched' => 10]);
        $this->assertFalse($this->eligibility->isReportable($result));

        config(['brand_wheel.report_eligibility_min_matched' => 3]);
        $this->assertTrue($this->eligibility->isReportable($result));
    }
}
