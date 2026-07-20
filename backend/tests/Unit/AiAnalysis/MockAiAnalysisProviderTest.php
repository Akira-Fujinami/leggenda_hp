<?php

namespace Tests\Unit\AiAnalysis;

use App\Services\AiAnalysis\Data\AiAnalysisInput;
use App\Services\AiAnalysis\Data\AiCompetitorGap;
use App\Services\AiAnalysis\Data\AiRecommendationSummary;
use App\Services\AiAnalysis\MockAiAnalysisProvider;
use Illuminate\Support\Collection;
use Tests\TestCase;

class MockAiAnalysisProviderTest extends TestCase
{
    private function makeInput(array $overrides = []): AiAnalysisInput
    {
        return new AiAnalysisInput(
            projectId: 1,
            analysisId: 1,
            websiteAnalysisId: 1,
            websiteName: $overrides['websiteName'] ?? '自社サイト',
            websiteUrl: 'https://example.com',
            industry: '旅行',
            purpose: '競合分析',
            overallScore: $overrides['overallScore'] ?? 62.5,
            categoryScores: new Collection,
            importantMetrics: new Collection,
            strengths: $overrides['strengths'] ?? ['技術SEOのスコアが高水準です', 'コンテンツの充実度が高い', 'アクセシビリティが良好'],
            weaknesses: $overrides['weaknesses'] ?? ['表示速度が遅い'],
            recommendations: $overrides['recommendations'] ?? new Collection([
                new AiRecommendationSummary('titleタグを設定してください。', 'technical_seo', 'high', 'high', 'small'),
            ]),
            competitorGaps: $overrides['competitorGaps'] ?? new Collection([
                new AiCompetitorGap(2, '競合サイト', 5.2),
            ]),
            coverageRate: 80.0,
            confidenceRate: 90.0,
            unavailableMetrics: [],
            errorMetrics: [],
        );
    }

    public function test_result_is_flagged_as_mock(): void
    {
        $result = (new MockAiAnalysisProvider)->analyze($this->makeInput())->result;

        $this->assertTrue($result->isMock);
        $this->assertSame('mock', $result->provider);
        $this->assertNull($result->model);
    }

    public function test_confidence_is_zero_for_mock_data(): void
    {
        $result = (new MockAiAnalysisProvider)->analyze($this->makeInput())->result;

        $this->assertSame(0.0, $result->confidence);
    }

    public function test_usage_tokens_are_null_for_mock_data(): void
    {
        $outcome = (new MockAiAnalysisProvider)->analyze($this->makeInput());

        $this->assertNull($outcome->usageInputTokens);
        $this->assertNull($outcome->usageOutputTokens);
    }

    public function test_summary_and_cautions_disclose_that_the_result_is_mock(): void
    {
        $result = (new MockAiAnalysisProvider)->analyze($this->makeInput())->result;

        $this->assertStringContainsString('モック', $result->summary);
        $this->assertNotEmpty($result->cautions);
        $this->assertStringContainsString('モック', $result->cautions[0]);
    }

    public function test_result_is_deterministic_for_the_same_input(): void
    {
        $input = $this->makeInput();
        $provider = new MockAiAnalysisProvider;

        $first = $provider->analyze($input)->result;
        $second = $provider->analyze($input)->result;

        $this->assertSame($first->toArray(), $second->toArray());
    }

    public function test_priority_actions_and_competitor_insights_are_derived_from_input(): void
    {
        $result = (new MockAiAnalysisProvider)->analyze($this->makeInput())->result;

        $this->assertSame('titleタグを設定してください。', $result->priorityActions[0]->title);
        $this->assertNotEmpty($result->competitorInsights);
        $this->assertStringContainsString('競合サイト', $result->competitorInsights[0]->description);
        $this->assertSame([2], $result->competitorInsights[0]->competitorWebsiteAnalysisIds);
    }

    public function test_strengths_and_weaknesses_are_structured_items(): void
    {
        $result = (new MockAiAnalysisProvider)->analyze($this->makeInput())->result;

        $this->assertSame('技術SEOのスコアが高水準です', $result->strengths[0]->title);
        $this->assertSame('表示速度が遅い', $result->weaknesses[0]->title);
    }

    public function test_name_returns_mock(): void
    {
        $this->assertSame('mock', (new MockAiAnalysisProvider)->name());
    }
}
