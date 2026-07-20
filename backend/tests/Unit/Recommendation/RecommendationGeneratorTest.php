<?php

namespace Tests\Unit\Recommendation;

use App\Enums\MetricResultStatus;
use App\Enums\RecommendationStatus;
use App\Models\CategoryDefinition;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\Recommendation;
use App\Models\WebsiteAnalysis;
use App\Services\Recommendation\RecommendationGenerator;
use App\Services\Recommendation\RecommendationPriorityCalculator;
use App\Services\Scoring\MetricScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private RecommendationGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new RecommendationGenerator(new MetricScorer, new RecommendationPriorityCalculator);
    }

    public function test_generates_a_recommendation_for_an_imperfect_metric_with_a_template(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'technical_seo', 'weight' => 20]);
        $definition = MetricDefinition::factory()->create([
            'category_key' => 'technical_seo',
            'scoring_type' => 'boolean',
            'max_score' => 5,
            'recommendation_template' => 'titleを設定してください。',
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        $result = MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success,
            'normalized_value' => ['value' => false],
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        $recommendation = Recommendation::query()->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertNotNull($recommendation);
        $this->assertSame('titleを設定してください。', $recommendation->description);
        $this->assertSame($result->id, $recommendation->metric_result_id);
        $this->assertSame(RecommendationStatus::Open, $recommendation->status);
        $this->assertGreaterThan(0, $recommendation->sort_score);
    }

    public function test_does_not_generate_a_recommendation_for_a_perfect_score(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'technical_seo', 'weight' => 20]);
        $definition = MetricDefinition::factory()->create([
            'category_key' => 'technical_seo', 'scoring_type' => 'boolean', 'max_score' => 5,
            'recommendation_template' => 'X',
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success,
            'normalized_value' => ['value' => true],
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        $this->assertSame(0, Recommendation::query()->count());
    }

    public function test_does_not_generate_a_recommendation_without_a_template(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'technical_seo', 'weight' => 20]);
        $definition = MetricDefinition::factory()->create([
            'category_key' => 'technical_seo', 'scoring_type' => 'boolean', 'max_score' => 5,
            'recommendation_template' => null,
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success,
            'normalized_value' => ['value' => false],
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        $this->assertSame(0, Recommendation::query()->count());
    }

    public function test_does_not_generate_a_recommendation_for_excluded_metrics(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'technical_seo', 'weight' => 20]);
        $definition = MetricDefinition::factory()->create([
            'category_key' => 'technical_seo', 'scoring_type' => 'boolean', 'max_score' => 5,
            'recommendation_template' => 'X',
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        MetricResult::factory()->unavailable()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'metric_definition_id' => $definition->id,
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        $this->assertSame(0, Recommendation::query()->count());
    }

    public function test_suppresses_tel_or_mailto_recommendation_when_a_contact_cta_already_exists(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'conversion', 'weight' => 15]);
        $telDefinition = MetricDefinition::factory()->create([
            'key' => 'tel_or_mailto_present', 'category_key' => 'conversion', 'scoring_type' => 'boolean',
            'max_score' => 3, 'recommendation_template' => '電話番号やメールアドレスへのリンクを設置してください。',
        ]);
        $contactDefinition = MetricDefinition::factory()->create([
            'key' => 'contact_cta_present', 'category_key' => 'conversion', 'scoring_type' => 'boolean',
            'max_score' => 3, 'recommendation_template' => '問い合わせへの導線を分かりやすく設置してください。',
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $telDefinition->id,
            'status' => MetricResultStatus::NotFound, 'normalized_value' => ['value' => false],
        ]);
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $contactDefinition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => true],
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        // 問い合わせ導線(contact_cta_present)は満点のため提案されず、
        // tel/mailtoは「他に問い合わせ手段がある」ため抑制されて提案されない。
        $this->assertSame(0, Recommendation::query()->count());
    }

    public function test_still_recommends_tel_or_mailto_when_no_other_contact_avenue_exists(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'conversion', 'weight' => 15]);
        $telDefinition = MetricDefinition::factory()->create([
            'key' => 'tel_or_mailto_present', 'category_key' => 'conversion', 'scoring_type' => 'boolean',
            'max_score' => 3, 'recommendation_template' => '電話番号やメールアドレスへのリンクを設置してください。',
        ]);
        $contactDefinition = MetricDefinition::factory()->create([
            'key' => 'contact_cta_present', 'category_key' => 'conversion', 'scoring_type' => 'boolean',
            'max_score' => 3, 'recommendation_template' => '問い合わせへの導線を分かりやすく設置してください。',
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $telDefinition->id,
            'status' => MetricResultStatus::NotFound, 'normalized_value' => ['value' => false],
        ]);
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $contactDefinition->id,
            'status' => MetricResultStatus::NotFound, 'normalized_value' => ['value' => false],
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        $this->assertSame(2, Recommendation::query()->count());
    }

    public function test_regenerating_does_not_create_duplicates(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'technical_seo', 'weight' => 20]);
        $definition = MetricDefinition::factory()->create([
            'category_key' => 'technical_seo', 'scoring_type' => 'boolean', 'max_score' => 5,
            'recommendation_template' => 'X',
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success,
            'normalized_value' => ['value' => false],
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        $this->assertSame(1, Recommendation::query()->count());
    }
}
