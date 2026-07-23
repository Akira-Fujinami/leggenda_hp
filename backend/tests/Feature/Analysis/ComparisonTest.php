<?php

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisStatus;
use App\Enums\MetricResultStatus;
use App\Enums\WebsiteAnalysisStatus;
use App\Models\Analysis;
use App\Models\CategoryDefinition;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use Database\Seeders\CategoryDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategoryDefinitionSeeder::class);
    }

    private function makeAnalysisWithTwoSites(User $user): array
    {
        $project = Project::factory()->for($user)->create();
        $primaryWebsite = Website::factory()->for($project)->create(['is_primary' => true, 'name' => '自社サイト']);
        $competitorWebsite = Website::factory()->for($project)->create(['is_primary' => false, 'name' => '競合サイト']);

        $analysis = Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Completed]);

        $primaryWa = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $primaryWebsite->id]);
        $competitorWa = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $competitorWebsite->id]);

        $definition = MetricDefinition::factory()->create([
            'key' => 'title_present', 'category_key' => 'technical_seo', 'scoring_type' => 'boolean', 'max_score' => 8,
            'recommendation_template' => 'titleを設定してください。',
        ]);

        MetricResult::factory()->create([
            'website_analysis_id' => $primaryWa->id, 'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => true],
        ]);
        MetricResult::factory()->create([
            'website_analysis_id' => $competitorWa->id, 'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => false],
        ]);

        return compact('project', 'primaryWebsite', 'competitorWebsite', 'analysis', 'primaryWa', 'competitorWa', 'definition');
    }

    public function test_comparison_endpoint_returns_ranking_and_never_leaks_raw_data(): void
    {
        $user = User::factory()->create();
        ['analysis' => $analysis, 'primaryWa' => $primaryWa] = $this->makeAnalysisWithTwoSites($user);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/comparison");

        $response->assertOk();
        $response->assertJsonPath('data.primary_website_analysis_id', $primaryWa->id);
        $response->assertJsonCount(2, 'data.ranking');
        $response->assertJsonPath('data.ranking.0.rank', 1);
        $response->assertJsonStructure(['data' => ['categories', 'metrics', 'strengths', 'weaknesses', 'data_quality']]);

        $raw = $response->getContent();
        $this->assertStringNotContainsString('raw_report', $raw);
        $this->assertStringNotContainsString('<html', $raw);
    }

    public function test_comparison_shows_gap_vs_primary_for_competitor(): void
    {
        $user = User::factory()->create();
        ['analysis' => $analysis, 'competitorWa' => $competitorWa] = $this->makeAnalysisWithTwoSites($user);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/comparison");

        $ranking = collect($response->json('data.ranking'));
        $competitorEntry = $ranking->firstWhere('website_analysis_id', $competitorWa->id);

        $this->assertNotNull($competitorEntry['score_gap_vs_primary']);
        $this->assertLessThan(0, $competitorEntry['score_gap_vs_primary']);
    }

    public function test_comparison_works_without_a_primary_site(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $website = Website::factory()->for($project)->create(['is_primary' => false]);
        $analysis = Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Completed]);
        WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/comparison");

        $response->assertOk();
        $response->assertJsonPath('data.primary_website_analysis_id', null);
        $response->assertJsonPath('data.ranking.0.score_gap_vs_primary', null);
    }

    public function test_mock_only_authority_category_is_reported_as_unavailable_not_a_fabricated_score(): void
    {
        $user = User::factory()->create();
        ['analysis' => $analysis, 'primaryWa' => $primaryWa, 'competitorWa' => $competitorWa] = $this->makeAnalysisWithTwoSites($user);

        $authorityDefinition = MetricDefinition::factory()->create([
            'key' => 'authority_score', 'category_key' => 'authority', 'scoring_type' => 'threshold', 'max_score' => 15,
        ]);

        foreach ([$primaryWa, $competitorWa] as $websiteAnalysis) {
            MetricResult::factory()->create([
                'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $authorityDefinition->id,
                'status' => MetricResultStatus::NotApplicable, 'score' => null, 'confidence' => 0,
                'normalized_value' => ['value' => 42],
            ]);
        }

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/comparison");

        $response->assertOk();
        $categories = collect($response->json('data.categories'));
        $authority = $categories->firstWhere('key', 'authority');

        $this->assertNotNull($authority);
        foreach ($authority['sites'] as $site) {
            $this->assertEquals(0.0, $site['max_available_score']);
            $this->assertEquals(0.0, $site['coverage_rate']);
        }

        // Mock-onlyのauthorityは強み・弱みのどちらにも出てこないこと。
        $strengths = collect($response->json('data.strengths'))->flatMap(fn ($group) => $group['items']);
        $weaknesses = collect($response->json('data.weaknesses'))->flatMap(fn ($group) => $group['items']);
        $this->assertFalse($strengths->contains('category_key', 'authority'));
        $this->assertFalse($weaknesses->contains('category_key', 'authority'));

        // ランキングはtechnical_seoの差分(1件目のtitle_presentのみ)に基づいたままであること。
        $ranking = collect($response->json('data.ranking'));
        $this->assertSame($primaryWa->id, $ranking->firstWhere('rank', 1)['website_analysis_id']);
    }

    public function test_other_user_cannot_view_comparison(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        ['analysis' => $analysis] = $this->makeAnalysisWithTwoSites($owner);

        $response = $this->actingAs($other)->getJson("/api/analyses/{$analysis->id}/comparison");

        $response->assertStatus(403);
    }

    public function test_recommendations_endpoint_lists_recommendations_for_the_analysis(): void
    {
        $user = User::factory()->create();
        ['analysis' => $analysis, 'competitorWa' => $competitorWa] = $this->makeAnalysisWithTwoSites($user);

        Recommendation::factory()->create(['website_analysis_id' => $competitorWa->id, 'title' => 'titleを設定してください。']);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/recommendations");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'titleを設定してください。');
    }

    public function test_recommendations_can_be_filtered_by_priority(): void
    {
        $user = User::factory()->create();
        ['competitorWa' => $competitorWa, 'analysis' => $analysis] = $this->makeAnalysisWithTwoSites($user);

        Recommendation::factory()->create(['website_analysis_id' => $competitorWa->id, 'priority' => 'critical']);
        Recommendation::factory()->create(['website_analysis_id' => $competitorWa->id, 'priority' => 'low']);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/recommendations?priority=critical");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.priority', 'critical');
    }

    public function test_website_analysis_recommendations_endpoint_scopes_to_one_site(): void
    {
        $user = User::factory()->create();
        ['primaryWa' => $primaryWa, 'competitorWa' => $competitorWa] = $this->makeAnalysisWithTwoSites($user);

        Recommendation::factory()->create(['website_analysis_id' => $primaryWa->id]);
        Recommendation::factory()->create(['website_analysis_id' => $competitorWa->id]);

        $response = $this->actingAs($user)->getJson("/api/website-analyses/{$primaryWa->id}/recommendations");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.website_analysis_id', $primaryWa->id);
    }
}
