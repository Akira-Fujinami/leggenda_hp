<?php

namespace Tests\Unit\Comparison;

use App\Services\Comparison\StrengthWeaknessExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrengthWeaknessExtractorTest extends TestCase
{
    use RefreshDatabase;

    private StrengthWeaknessExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new StrengthWeaknessExtractor;
    }

    public function test_a_mock_only_category_is_excluded_from_weaknesses_even_though_its_ratio_is_zero(): void
    {
        // score=0/configured_max_score=15の比率だけを見れば「弱み」判定の閾値
        // (<=50%)を満たしてしまうが、coverage_rate=0(全指標がMock/未取得)の
        // カテゴリは、実際に測定できていないだけであり「弱み」ではないため、
        // MIN_CATEGORY_COVERAGEガードにより除外されるべき。
        $categoryComparisons = [[
            'key' => 'authority',
            'name' => '外部SEO',
            'configured_max_score' => 15.0,
            'sites' => [
                ['website_analysis_id' => 1, 'score' => 0.0, 'max_available_score' => 0.0, 'coverage_rate' => 0.0, 'gap_vs_primary' => null],
            ],
        ]];

        $result = $this->extractor->extract(1, $categoryComparisons, [], collect());

        $this->assertEmpty($result['weaknesses']);
        $this->assertEmpty($result['strengths']);
    }

    public function test_a_genuinely_low_scoring_but_measured_category_is_still_reported_as_a_weakness(): void
    {
        $categoryComparisons = [[
            'key' => 'performance',
            'name' => '表示速度',
            'configured_max_score' => 15.0,
            'sites' => [
                ['website_analysis_id' => 1, 'score' => 2.0, 'max_available_score' => 15.0, 'coverage_rate' => 100.0, 'gap_vs_primary' => null],
            ],
        ]];

        $result = $this->extractor->extract(1, $categoryComparisons, [], collect());

        $this->assertCount(1, $result['weaknesses']);
        $this->assertSame('performance', $result['weaknesses'][0]['category_key']);
    }

    public function test_a_mock_metric_status_not_applicable_is_excluded_from_metric_level_strengths_and_weaknesses(): void
    {
        // status='not_applicable'(Mock由来)の指標は「未取得・エラー」と同様、
        // status!=='success'ガードにより強み・弱み判定の対象外になる。
        $metricComparisons = [[
            'key' => 'authority_score',
            'name' => 'Authority Score',
            'category_key' => 'authority',
            'value_type' => 'number',
            'unit' => null,
            'source_type' => 'semrush',
            'higher_is_better' => true,
            'sites' => [
                ['website_analysis_id' => 1, 'status' => 'not_applicable', 'value' => 5, 'confidence' => 0.0, 'evidence' => null, 'measured_at' => null, 'error_code' => null, 'error_message' => null, 'is_mock' => true, 'gap_vs_primary' => null],
                ['website_analysis_id' => 2, 'status' => 'success', 'value' => 60, 'confidence' => 1.0, 'evidence' => null, 'measured_at' => null, 'error_code' => null, 'error_message' => null, 'is_mock' => false, 'gap_vs_primary' => null],
            ],
        ]];

        $result = $this->extractor->extract(1, [], $metricComparisons, collect());

        $this->assertEmpty($result['strengths']);
        $this->assertEmpty($result['weaknesses']);
    }
}
