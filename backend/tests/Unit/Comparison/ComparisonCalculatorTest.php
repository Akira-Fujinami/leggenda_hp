<?php

namespace Tests\Unit\Comparison;

use App\Models\CategoryDefinition;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Comparison\ComparisonCalculator;
use App\Support\Comparison\SiteScoreEntry;
use App\Support\Scoring\CategoryScoreResult;
use App\Support\Scoring\WebsiteScoreResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComparisonCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private ComparisonCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new ComparisonCalculator;
    }

    private function entry(CategoryScoreResult $authorityScore): SiteScoreEntry
    {
        $website = Website::factory()->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['website_id' => $website->id]);

        $score = new WebsiteScoreResult(
            overallScore: 50,
            displayScore: 50,
            availableScore: 50,
            configuredMaxScore: 100,
            coverageRate: 80,
            confidenceRate: 90,
            categoryScores: collect([$authorityScore]),
            metricSummary: ['success' => 1, 'not_found' => 0, 'unavailable' => 0, 'error' => 0, 'not_applicable' => 1],
        );

        return new SiteScoreEntry($websiteAnalysis, $score);
    }

    public function test_a_mock_only_category_is_reported_as_max_available_zero_not_a_fabricated_score(): void
    {
        // authorityの全指標がMock/not_applicableだったケース ―― 採点エンジンが
        // 既に生成するmax_available_score=0/coverage_rate=0がそのまま比較APIの
        // 出力にも伝播することを保証する回帰テスト(表示側で「0/15」のような
        // 捏造に見える値にしないための前提条件)。
        $authorityDefinition = CategoryDefinition::factory()->create(['key' => 'authority', 'name' => '外部SEO', 'weight' => 15]);
        $entry = $this->entry(new CategoryScoreResult('authority', '外部SEO', score: 0.0, maxAvailableScore: 0.0, configuredMaxScore: 15, coverageRate: 0.0));

        $result = $this->calculator->compareCategories(collect([$entry]), collect([$authorityDefinition]), null);

        $this->assertSame(0.0, $result[0]['sites'][0]['max_available_score']);
        $this->assertSame(0.0, $result[0]['sites'][0]['coverage_rate']);
        $this->assertSame(0.0, $result[0]['sites'][0]['score']);
        $this->assertSame(15.0, $result[0]['configured_max_score']);
    }

    public function test_a_fully_measured_category_reports_its_real_score(): void
    {
        $definition = CategoryDefinition::factory()->create(['key' => 'authority', 'name' => '外部SEO', 'weight' => 15]);
        $entry = $this->entry(new CategoryScoreResult('authority', '外部SEO', score: 12.0, maxAvailableScore: 15.0, configuredMaxScore: 15, coverageRate: 100.0));

        $result = $this->calculator->compareCategories(collect([$entry]), collect([$definition]), null);

        $this->assertSame(15.0, $result[0]['sites'][0]['max_available_score']);
        $this->assertSame(12.0, $result[0]['sites'][0]['score']);
    }
}
