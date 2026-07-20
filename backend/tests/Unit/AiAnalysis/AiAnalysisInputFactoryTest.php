<?php

namespace Tests\Unit\AiAnalysis;

use App\Enums\MetricResultStatus;
use App\Models\Analysis;
use App\Models\CategoryDefinition;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\AiAnalysis\AiAnalysisInputFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAnalysisInputFactoryTest extends TestCase
{
    use RefreshDatabase;

    private AiAnalysisInputFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = app(AiAnalysisInputFactory::class);
    }

    private function makeAnalysisWithCompetitor(): array
    {
        $project = Project::factory()->create(['industry' => '旅行', 'purpose' => '競合分析']);
        $targetWebsite = Website::factory()->for($project)->create(['name' => '自社サイト', 'is_primary' => true]);
        $competitorWebsite = Website::factory()->for($project)->create(['name' => '競合サイト', 'is_primary' => false]);

        $analysis = Analysis::factory()->for($project)->completed()->create();
        $targetWa = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $targetWebsite->id]);
        $competitorWa = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $competitorWebsite->id]);

        $category = CategoryDefinition::factory()->create(['key' => 'technical_seo', 'name' => '技術SEO', 'weight' => 20]);

        $definition = MetricDefinition::factory()->create([
            'key' => 'title_present',
            'name' => 'titleタグ',
            'category_key' => 'technical_seo',
            'scoring_type' => 'boolean',
            'max_score' => 10,
            'weight' => 5,
            'recommendation_template' => 'titleタグを設定してください。',
        ]);

        MetricResult::factory()->create([
            'website_analysis_id' => $targetWa->id,
            'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success,
            'normalized_value' => ['value' => false],
            'confidence' => 1.0,
        ]);
        MetricResult::factory()->create([
            'website_analysis_id' => $competitorWa->id,
            'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success,
            'normalized_value' => ['value' => true],
            'confidence' => 1.0,
        ]);

        Recommendation::factory()->create([
            'website_analysis_id' => $targetWa->id,
            'metric_result_id' => MetricResult::query()->where('website_analysis_id', $targetWa->id)->first()->id,
            'category_key' => 'technical_seo',
            'title' => 'titleタグを設定してください。',
            'status' => 'open',
            'priority' => 'high',
            'sort_score' => 80,
        ]);

        return compact('project', 'analysis', 'targetWa', 'competitorWa', 'definition');
    }

    public function test_builds_input_from_existing_scoring_comparison_and_recommendation_data(): void
    {
        ['project' => $project, 'analysis' => $analysis, 'targetWa' => $targetWa, 'competitorWa' => $competitorWa] = $this->makeAnalysisWithCompetitor();

        $input = $this->factory->build($targetWa->fresh());

        $this->assertSame($project->id, $input->projectId);
        $this->assertSame($analysis->id, $input->analysisId);
        $this->assertSame($targetWa->id, $input->websiteAnalysisId);
        $this->assertSame('自社サイト', $input->websiteName);
        $this->assertSame('旅行', $input->industry);
        $this->assertSame('競合分析', $input->purpose);

        $this->assertGreaterThanOrEqual(0, $input->overallScore);
        $this->assertGreaterThan(0, $input->categoryScores->count());
        $this->assertSame('title_present', $input->importantMetrics->first()->key);

        $this->assertCount(1, $input->competitorGaps);
        $this->assertSame('競合サイト', $input->competitorGaps->first()->competitorName);

        $this->assertCount(1, $input->recommendations);
        $this->assertSame('titleタグを設定してください。', $input->recommendations->first()->title);

        $this->assertContains('titleタグを設定してください。', $input->weaknesses);
    }

    public function test_does_not_leak_raw_html_or_raw_metric_data(): void
    {
        ['targetWa' => $targetWa] = $this->makeAnalysisWithCompetitor();

        $definition = MetricDefinition::factory()->create([
            'key' => 'lcp', 'category_key' => 'technical_seo', 'scoring_type' => 'inverse_linear',
        ]);
        MetricResult::factory()->create([
            'website_analysis_id' => $targetWa->id,
            'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success,
            'normalized_value' => ['value' => 1200],
            'raw_value' => ['html' => '<html>RAW_HTML_MARKER_SHOULD_NOT_LEAK</html>'],
            'evidence' => ['secret_marker' => 'SECRET_EVIDENCE_MARKER'],
        ]);

        $input = $this->factory->build($targetWa->fresh());
        $encoded = json_encode($input->toArray());

        $this->assertStringNotContainsString('RAW_HTML_MARKER_SHOULD_NOT_LEAK', $encoded);
        $this->assertStringNotContainsString('SECRET_EVIDENCE_MARKER', $encoded);
        $this->assertStringNotContainsString('<html>', $encoded);
    }

    public function test_unavailable_and_error_metrics_are_reported_by_name(): void
    {
        ['targetWa' => $targetWa] = $this->makeAnalysisWithCompetitor();

        $unavailableDefinition = MetricDefinition::factory()->create([
            'key' => 'lighthouse_performance', 'name' => 'Lighthouseパフォーマンス', 'category_key' => 'technical_seo',
        ]);
        MetricResult::factory()->unavailable()->create([
            'website_analysis_id' => $targetWa->id,
            'metric_definition_id' => $unavailableDefinition->id,
        ]);

        $input = $this->factory->build($targetWa->fresh());

        $this->assertContains('Lighthouseパフォーマンス', $input->unavailableMetrics);
    }
}
