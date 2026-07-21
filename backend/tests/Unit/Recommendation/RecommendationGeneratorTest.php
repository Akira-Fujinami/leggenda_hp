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

    public function test_downgrades_tel_or_mailto_priority_to_low_when_chatbot_is_detected(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'conversion', 'weight' => 15]);
        $telDefinition = MetricDefinition::factory()->create([
            'key' => 'tel_or_mailto_present', 'category_key' => 'conversion', 'scoring_type' => 'boolean',
            'max_score' => 5, 'recommendation_template' => '電話番号やメールアドレスへのリンクを設置してください。',
        ]);
        $chatbotDefinition = MetricDefinition::factory()->create([
            'key' => 'chatbot_detected', 'category_key' => 'conversion', 'scoring_type' => 'not_scored',
            'max_score' => 0,
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $telDefinition->id,
            'status' => MetricResultStatus::NotFound, 'normalized_value' => ['value' => false],
        ]);
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $chatbotDefinition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => true],
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        $recommendation = Recommendation::query()->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertNotNull($recommendation);
        $this->assertSame(\App\Enums\RecommendationPriority::Low, $recommendation->priority);
        $this->assertStringContainsString('チャットサポート', $recommendation->description);
    }

    public function test_faq_alone_only_softens_the_description_without_changing_priority_or_suppressing(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'conversion', 'weight' => 15]);
        $telDefinition = MetricDefinition::factory()->create([
            'key' => 'tel_or_mailto_present', 'category_key' => 'conversion', 'scoring_type' => 'boolean',
            'max_score' => 5, 'recommendation_template' => '電話番号やメールアドレスへのリンクを設置してください。',
        ]);
        $faqDefinition = MetricDefinition::factory()->create([
            'key' => 'faq_link_present', 'category_key' => 'conversion', 'scoring_type' => 'not_scored',
            'max_score' => 0,
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $telDefinition->id,
            'status' => MetricResultStatus::NotFound, 'normalized_value' => ['value' => false],
        ]);
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $faqDefinition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => true],
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        // 完全抑制されず、提案自体は生成される(「緊急対応」の断定はしないが
        // 提案そのものを消してしまうのは過剰断定になるため)。
        $recommendation = Recommendation::query()->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertNotNull($recommendation);
        $this->assertNotSame(\App\Enums\RecommendationPriority::Low, $recommendation->priority);
        $this->assertStringContainsString('FAQ', $recommendation->description);
    }

    public function test_low_confidence_does_not_escalate_a_near_zero_ratio_to_critical(): void
    {
        // Lighthouse単発計測(confidence=0.75)のような低confidenceの結果は、
        // ratio<=0.01であっても無条件にCriticalへ引き上げない
        // (単発の極端な値を確定的な緊急事態と断定しないための安全弁)。
        $category = CategoryDefinition::factory()->create(['key' => 'performance', 'weight' => 20]);
        $definition = MetricDefinition::factory()->create([
            'key' => 'lcp', 'category_key' => 'performance', 'scoring_type' => 'boolean',
            'max_score' => 5, 'recommendation_template' => 'LCPを改善してください。',
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => false],
            'confidence' => 0.75,
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        $recommendation = Recommendation::query()->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertNotNull($recommendation);
        $this->assertSame(\App\Enums\RecommendationPriority::High, $recommendation->priority);
    }

    public function test_high_confidence_still_escalates_a_near_zero_ratio_to_critical(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'performance', 'weight' => 20]);
        $definition = MetricDefinition::factory()->create([
            'key' => 'lcp', 'category_key' => 'performance', 'scoring_type' => 'boolean',
            'max_score' => 5, 'recommendation_template' => 'LCPを改善してください。',
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => false],
            'confidence' => 0.95,
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        $recommendation = Recommendation::query()->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertSame(\App\Enums\RecommendationPriority::Critical, $recommendation->priority);
    }

    public function test_downgrades_the_form_present_recommendation_when_help_center_is_detected(): void
    {
        // section6の回帰テスト: 問い合わせフォームが無くてもヘルプ導線が
        // あれば「問い合わせフォームを設置してください」を緊急扱いしない。
        $category = CategoryDefinition::factory()->create(['key' => 'conversion', 'weight' => 15]);
        $formDefinition = MetricDefinition::factory()->create([
            'key' => 'form_present', 'category_key' => 'conversion', 'scoring_type' => 'boolean',
            'max_score' => 5, 'recommendation_template' => '問い合わせフォームを設置してください。',
        ]);
        $helpCenterDefinition = MetricDefinition::factory()->create([
            'key' => 'help_center_link_present', 'category_key' => 'conversion', 'scoring_type' => 'not_scored',
            'max_score' => 0,
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $formDefinition->id,
            'status' => MetricResultStatus::NotFound, 'normalized_value' => ['value' => false],
        ]);
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $helpCenterDefinition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => true],
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        $recommendation = Recommendation::query()->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertNotNull($recommendation);
        $this->assertSame(\App\Enums\RecommendationPriority::Low, $recommendation->priority);
        $this->assertStringContainsString('ヘルプ・サポートページ', $recommendation->description);
    }

    public function test_alt_coverage_at_98_67_percent_is_low_priority(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'content', 'weight' => 20]);
        $definition = MetricDefinition::factory()->create([
            'key' => 'img_alt_coverage', 'category_key' => 'content', 'scoring_type' => 'ratio',
            'max_score' => 4, 'recommendation_template' => '重要な画像に代替テキスト(alt)を設定してください。',
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => 0.9867],
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        $recommendation = Recommendation::query()->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertNotNull($recommendation);
        $this->assertSame(\App\Enums\RecommendationPriority::Low, $recommendation->priority);
    }

    public function test_alt_coverage_at_86_79_percent_is_medium_priority(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'content', 'weight' => 20]);
        $definition = MetricDefinition::factory()->create([
            'key' => 'img_alt_coverage', 'category_key' => 'content', 'scoring_type' => 'ratio',
            'max_score' => 4, 'recommendation_template' => '重要な画像に代替テキスト(alt)を設定してください。',
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => 0.8679],
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        $recommendation = Recommendation::query()->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertSame(\App\Enums\RecommendationPriority::Medium, $recommendation->priority);
    }

    public function test_alt_coverage_below_50_percent_is_high_or_critical_priority(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'content', 'weight' => 20]);
        $definition = MetricDefinition::factory()->create([
            'key' => 'img_alt_coverage', 'category_key' => 'content', 'scoring_type' => 'ratio',
            'max_score' => 4, 'recommendation_template' => '重要な画像に代替テキスト(alt)を設定してください。',
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => 0.20],
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        $recommendation = Recommendation::query()->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertContains($recommendation->priority, [\App\Enums\RecommendationPriority::High, \App\Enums\RecommendationPriority::Critical]);
    }

    public function test_alt_priority_thresholds_do_not_apply_to_other_ratio_metrics(): void
    {
        // img_alt_coverage専用の閾値が、他のratio指標に誤って流用されない
        // ことの確認(scoring_type全体には一律適用しない)。
        $category = CategoryDefinition::factory()->create(['key' => 'technical_seo', 'weight' => 20]);
        $definition = MetricDefinition::factory()->create([
            'key' => 'some_other_ratio_metric', 'category_key' => 'technical_seo', 'scoring_type' => 'ratio',
            'max_score' => 4, 'recommendation_template' => 'X',
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => 0.9867],
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        $recommendation = Recommendation::query()->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertNotNull($recommendation);
        // alt用の閾値(0.9867>=0.95→Low)ではなく、既存のclassifyPriority
        // (impact基準、この場合はmaxScore>=3によりHigh、ratio<=0.01ではないためHigh)にフォールバックする。
        $this->assertSame(\App\Enums\RecommendationPriority::High, $recommendation->priority);
    }

    public function test_h1_zero_valid_count_uses_the_default_no_h1_template(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'technical_seo', 'weight' => 20]);
        $definition = MetricDefinition::factory()->create([
            'key' => 'h1_single', 'category_key' => 'technical_seo', 'scoring_type' => 'boolean',
            'max_score' => 5, 'recommendation_template' => 'ページの主題を表すH1を設定してください。',
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::NotFound, 'normalized_value' => ['value' => false],
            'raw_value' => ['count' => 0, 'valid_count' => 0],
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        $recommendation = Recommendation::query()->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertNotNull($recommendation);
        $this->assertSame('ページの主題を表すH1を設定してください。', $recommendation->description);
    }

    public function test_h1_valid_count_one_does_not_generate_a_recommendation(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'technical_seo', 'weight' => 20]);
        $definition = MetricDefinition::factory()->create([
            'key' => 'h1_single', 'category_key' => 'technical_seo', 'scoring_type' => 'boolean',
            'max_score' => 5, 'recommendation_template' => 'ページの主題を表すH1を設定してください。',
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => true],
            'raw_value' => ['count' => 1, 'valid_count' => 1],
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        $this->assertSame(0, Recommendation::query()->count());
    }

    public function test_h1_multiple_valid_count_uses_a_distinct_description_from_zero_valid_count(): void
    {
        $category = CategoryDefinition::factory()->create(['key' => 'technical_seo', 'weight' => 20]);
        $definition = MetricDefinition::factory()->create([
            'key' => 'h1_single', 'category_key' => 'technical_seo', 'scoring_type' => 'boolean',
            'max_score' => 5, 'recommendation_template' => 'ページの主題を表すH1を設定してください。',
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        // status=Successのままnormalized_value=falseになる(count>0をfalse扱い
        // しない)ケース ―― それでもratio=0(exactly-oneを満たさない)ため
        // 提案自体は生成されるが、文言は「H1なし」用のデフォルトとは異なる
        // ものになるべき。
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => false],
            'raw_value' => ['count' => 2, 'valid_count' => 2],
        ]);

        $results = MetricResult::query()->with('metricDefinition')->get();
        $this->generator->generate($websiteAnalysis, $results, collect([$category]));

        $recommendation = Recommendation::query()->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertNotNull($recommendation);
        $this->assertNotSame('ページの主題を表すH1を設定してください。', $recommendation->description);
        $this->assertStringContainsString('複数', $recommendation->description);
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
