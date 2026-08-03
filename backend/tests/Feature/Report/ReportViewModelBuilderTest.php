<?php

namespace Tests\Feature\Report;

use App\Enums\AnalysisStatus;
use App\Enums\MetricResultStatus;
use App\Enums\RecommendationEffort;
use App\Enums\RecommendationImpact;
use App\Enums\RecommendationPriority;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\LeadSession;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Report\ReportViewModelBuilder;
use App\Support\Lead\LeadMetricCatalog;
use App\Support\Lead\LeadRecommendationCatalog;
use Database\Seeders\CategoryDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportViewModelBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategoryDefinitionSeeder::class);
    }

    private function makeWebsiteAnalysis(Analysis $analysis, bool $isPrimary): WebsiteAnalysis
    {
        $website = Website::factory()->create(['project_id' => $analysis->project_id, 'is_primary' => $isPrimary]);

        return WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
    }

    private function recordSuccessfulMetric(WebsiteAnalysis $wa, string $categoryKey, float $score, float $maxScore): void
    {
        $definition = MetricDefinition::factory()->create([
            'category_key' => $categoryKey,
            'source_type' => 'static_html',
            'weight' => $maxScore,
        ]);

        MetricResult::factory()->create([
            'website_analysis_id' => $wa->id,
            'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success,
            'score' => $score,
            'max_score' => $maxScore,
        ]);
    }

    public function test_a_self_only_analysis_has_no_competitor_score_or_comparison_sentence(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $this->recordSuccessfulMetric($selfWa, 'accessibility', 8, 10);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNull($viewModel->competitorScore);
        $this->assertNull($viewModel->comparisonSentence);
        $this->assertSame('株式会社サンプル様', $viewModel->companyDisplayName);
        $this->assertFalse($viewModel->isPartial);
    }

    public function test_an_analysis_with_a_competitor_gets_a_comparison_sentence(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, isPrimary: false);
        $this->recordSuccessfulMetric($selfWa, 'accessibility', 9, 10);
        $this->recordSuccessfulMetric($competitorWa, 'accessibility', 9, 10);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNotNull($viewModel->competitorScore);
        $this->assertNotNull($viewModel->comparisonSentence);
    }

    /**
     * Phase 3: 内部の7カテゴリ別内訳(旧categoryBreakdown、
     * CategoryAvailabilityClassifierによる計測対象外/評価不可判定)は
     * 採用担当向けの4観点(perspectives)へ置き換わった。同等の
     * 計測対象外/評価不可の書き分けは、観点①(採用ページ)について
     * LeadPerspectiveComposerTest側でカバーする。
     */
    public function test_view_model_exposes_four_lead_perspectives_instead_of_internal_categories(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertCount(4, $viewModel->perspectives);
        $this->assertEqualsCanonicalizing(
            ['completeness', 'clarity', 'findability', 'usability'],
            array_column($viewModel->perspectives, 'key'),
        );
    }

    /**
     * LeadRecommendationCatalogに載っている7キー分の改善提案を作り、
     * sort_score降順で並べ替え・上位5件への絞り込みを検証する
     * (2026-08-03: LeadRecommendationComposer経由になったため、
     * metric_result_id経由でcatalog許可キーに解決できることが前提)。
     */
    private function recommendationWithCatalogKey(WebsiteAnalysis $wa, string $metricKey, float $sortScore, array $overrides = []): Recommendation
    {
        $definition = MetricDefinition::factory()->create(['key' => $metricKey]);
        $result = MetricResult::factory()->create([
            'website_analysis_id' => $wa->id,
            'metric_definition_id' => $definition->id,
        ]);

        return Recommendation::factory()->create(array_merge([
            'website_analysis_id' => $wa->id,
            'metric_result_id' => $result->id,
            'sort_score' => $sortScore,
        ], $overrides));
    }

    public function test_top_recommendations_are_sorted_by_sort_score_and_capped_at_five_with_correct_labels(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        $catalogKeys = [
            'title_present', 'meta_description_present', 'h1_single', 'heading_structure_present',
            'word_count_sufficient', 'internal_link_sufficient', 'form_present',
        ];

        foreach ($catalogKeys as $i => $key) {
            $sortScore = $i + 1;
            $this->recommendationWithCatalogKey($selfWa, $key, $sortScore, [
                'priority' => $sortScore === 7 ? RecommendationPriority::Critical : RecommendationPriority::Medium,
                'impact' => RecommendationImpact::High,
                'effort' => RecommendationEffort::Small,
            ]);
        }

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);
        $catalog = new LeadRecommendationCatalog(new LeadMetricCatalog);

        $this->assertCount(5, $viewModel->topRecommendations);
        // 最後(sort_score=7)はform_present。
        $this->assertSame($catalog->title('form_present'), $viewModel->topRecommendations[0]->title);
        $this->assertSame('緊急', $viewModel->topRecommendations[0]->priorityLabel);
        // 次点(sort_score=6)はinternal_link_sufficient。
        $this->assertSame($catalog->title('internal_link_sufficient'), $viewModel->topRecommendations[1]->title);
    }

    /**
     * #A-2/カタログ整合性に続く2026-08-03の設計: 4観点(LeadMetricCatalog)に
     * 無いキー(例: analytics_configured)は、レポートにも一切出さない
     * ―― 画面だけ直してレポートに技術用語が残る食い違いを作らないため。
     */
    public function test_top_recommendations_excludes_a_metric_not_in_the_lead_metric_catalog(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        // 4観点に無いキー。sort_scoreを最大にしても出てはならない。
        $this->recommendationWithCatalogKey($selfWa, 'analytics_configured', 999.0);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame([], $viewModel->topRecommendations);
    }

    /**
     * レポートのtitle/descriptionが、画面(LeadAnalysisController)と同じ
     * LeadRecommendationCatalogの文言そのものであることを固定する
     * (raw metric_definitions.name/recommendation_templateではないこと)。
     */
    public function test_top_recommendations_use_lead_recommendation_catalog_wording_not_raw_technical_text(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        $this->recommendationWithCatalogKey($selfWa, 'form_present', 1.0, [
            'title' => 'フォーム有無',
            'description' => '問い合わせフォームを設置してください。',
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);
        $catalog = new LeadRecommendationCatalog(new LeadMetricCatalog);

        $this->assertCount(1, $viewModel->topRecommendations);
        $this->assertSame($catalog->title('form_present'), $viewModel->topRecommendations[0]->title);
        $this->assertSame($catalog->description('form_present'), $viewModel->topRecommendations[0]->description);
        $this->assertNotSame('フォーム有無', $viewModel->topRecommendations[0]->title);
    }

    public function test_a_partial_analysis_still_produces_a_view_model(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Partial]);
        $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertTrue($viewModel->isPartial);
        $this->assertNotEmpty($viewModel->perspectives);
    }

    /**
     * 2026-08-03: 画面(LeadResults)から診断内容そのものを外したことで、
     * レポート(Word/PDF)が「競合サイトへの改善提案は出さない」という
     * 誠実性保証の唯一の配信経路になった。ReportViewModelBuilder::build()は
     * $selfWebsiteAnalysis->recommendationsのみをComposerへ渡す実装だが、
     * それを固定するリグレッションテストが存在しなかった(既存テストは
     * いずれもself側にしかrecommendationsを作っていない) ―― この抜けを埋める。
     */
    public function test_top_recommendations_never_include_the_competitor_sites_recommendations(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, isPrimary: false);

        $this->recommendationWithCatalogKey($selfWa, 'form_present', 1.0);
        // 競合側にsort_scoreを圧倒的に高く設定する ―― もし将来の実装ミスで
        // 競合のrecommendationsが混ざってしまった場合、catalog上位に来て
        // このテストが検出できるようにするため。
        $this->recommendationWithCatalogKey($competitorWa, 'title_present', 999.0);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);
        $catalog = new LeadRecommendationCatalog(new LeadMetricCatalog);

        $this->assertCount(1, $viewModel->topRecommendations);
        $this->assertSame($catalog->title('form_present'), $viewModel->topRecommendations[0]->title);
    }

    public function test_brand_wheel_is_composed_for_self_and_competitor_websites(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, isPrimary: false);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => '自社サイトの抜粋']]]],
            'key_message' => '自社のキーメッセージ',
            'impression' => '自社の印象',
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'asset', 'matched_sub_elements' => [['key' => 'brand_recognition', 'evidence' => '競合サイトの抜粋']]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame('success', $viewModel->brandWheelSelf['status']);
        $this->assertSame('自社のキーメッセージ', $viewModel->brandWheelSelf['key_message']);
        $this->assertSame('success', $viewModel->brandWheelCompetitor['status']);
        $this->assertNotEmpty($viewModel->brandWheelComparison['self_points']);

        // evidence(原文の抜粋)はレポート層にも一切渡さない
        // (BrandWheelLeadResponseComposerと同じ方針)。
        $raw = json_encode($viewModel->brandWheelSelf, JSON_UNESCAPED_UNICODE).json_encode($viewModel->brandWheelCompetitor, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('自社サイトの抜粋', $raw);
        $this->assertStringNotContainsString('競合サイトの抜粋', $raw);
    }

    /**
     * status!=='success'のとき(6項目すべて0件の表を出すことは禁止のため)
     * axesが空配列でReportViewModelへ渡ること。実際に表を出さない判断は
     * Blade/WordReportGenerator側のstatus分岐に一任する。
     */
    public function test_brand_wheel_axes_are_empty_when_status_is_not_success(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'insufficient_input',
            'source_pages' => ['recruit_page' => 'absent', 'home_page' => 'read'],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame('insufficient_input', $viewModel->brandWheelSelf['status']);
        $this->assertSame([], $viewModel->brandWheelSelf['axes']);
        $this->assertNotNull($viewModel->brandWheelSelf['status_message']);
        $this->assertNull($viewModel->brandWheelComparison['one_point']);
    }

    public function test_brand_wheel_competitor_is_null_for_a_self_only_analysis(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNull($viewModel->brandWheelCompetitor);
        $this->assertSame([], $viewModel->brandWheelComparison['competitor_points']);
    }
}
