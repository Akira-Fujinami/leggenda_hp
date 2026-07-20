<?php

namespace Tests\Unit\Comparison;

use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Comparison\RankingCalculator;
use App\Support\Comparison\SiteScoreEntry;
use App\Support\Scoring\CategoryScoreResult;
use App\Support\Scoring\WebsiteScoreResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankingCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private RankingCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new RankingCalculator;
    }

    private function entry(float $overallScore, float $coverageRate = 100, float $confidenceRate = 100, int $displayOrder = 0, float $technicalSeo = 0, float $performance = 0): SiteScoreEntry
    {
        $website = Website::factory()->create(['display_order' => $displayOrder]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['website_id' => $website->id]);

        $categories = collect([
            new CategoryScoreResult('technical_seo', '技術SEO', $technicalSeo, 20, 20, 100),
            new CategoryScoreResult('performance', '表示速度', $performance, 15, 15, 100),
        ]);

        $score = new WebsiteScoreResult(
            overallScore: $overallScore,
            displayScore: (int) round($overallScore),
            availableScore: $overallScore,
            configuredMaxScore: 100,
            coverageRate: $coverageRate,
            confidenceRate: $confidenceRate,
            categoryScores: $categories,
            metricSummary: ['success' => 1, 'not_found' => 0, 'unavailable' => 0, 'error' => 0, 'not_applicable' => 0],
        );

        return new SiteScoreEntry($websiteAnalysis, $score);
    }

    public function test_ranks_by_overall_score_descending(): void
    {
        $low = $this->entry(50);
        $high = $this->entry(90);

        $ranked = $this->calculator->rank(collect([$low, $high]));

        $this->assertSame(1, $ranked[0]->rank);
        $this->assertSame($high->websiteAnalysis->id, $ranked[0]->entry->websiteAnalysis->id);
        $this->assertSame(2, $ranked[1]->rank);
    }

    public function test_breaks_ties_using_coverage_rate(): void
    {
        $lowCoverage = $this->entry(80, coverageRate: 40);
        $highCoverage = $this->entry(80, coverageRate: 90);

        $ranked = $this->calculator->rank(collect([$lowCoverage, $highCoverage]));

        $this->assertSame($highCoverage->websiteAnalysis->id, $ranked[0]->entry->websiteAnalysis->id);
    }

    public function test_breaks_further_ties_using_display_order(): void
    {
        $second = $this->entry(80, coverageRate: 80, confidenceRate: 80, displayOrder: 2);
        $first = $this->entry(80, coverageRate: 80, confidenceRate: 80, displayOrder: 1);

        $ranked = $this->calculator->rank(collect([$second, $first]));

        $this->assertSame($first->websiteAnalysis->id, $ranked[0]->entry->websiteAnalysis->id);
    }

    public function test_flags_low_coverage_sites_with_a_warning(): void
    {
        $lowData = $this->entry(90, coverageRate: 30);

        $ranked = $this->calculator->rank(collect([$lowData]));

        $this->assertTrue($ranked[0]->lowDataWarning);
    }

    public function test_does_not_flag_sufficient_coverage(): void
    {
        $goodData = $this->entry(90, coverageRate: 80);

        $ranked = $this->calculator->rank(collect([$goodData]));

        $this->assertFalse($ranked[0]->lowDataWarning);
    }
}
