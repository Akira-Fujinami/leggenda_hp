<?php

namespace Tests\Feature\Report;

use App\Enums\AnalysisStatus;
use App\Enums\MetricResultStatus;
use App\Enums\RecommendationEffort;
use App\Enums\RecommendationImpact;
use App\Enums\RecommendationPriority;
use App\Models\Analysis;
use App\Models\LeadSession;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Report\ReportViewModelBuilder;
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

    public function test_top_recommendations_are_sorted_by_sort_score_and_capped_at_five_with_correct_labels(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        foreach (range(1, 7) as $i) {
            Recommendation::factory()->create([
                'website_analysis_id' => $selfWa->id,
                'title' => "改善提案{$i}",
                'sort_score' => $i,
                'priority' => $i === 7 ? RecommendationPriority::Critical : RecommendationPriority::Medium,
                'impact' => RecommendationImpact::High,
                'effort' => RecommendationEffort::Small,
            ]);
        }

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertCount(5, $viewModel->topRecommendations);
        $this->assertSame('改善提案7', $viewModel->topRecommendations[0]->title);
        $this->assertSame('緊急', $viewModel->topRecommendations[0]->priorityLabel);
        $this->assertSame('改善提案6', $viewModel->topRecommendations[1]->title);
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
}
