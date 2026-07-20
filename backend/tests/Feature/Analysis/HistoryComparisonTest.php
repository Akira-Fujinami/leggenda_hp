<?php

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisStatus;
use App\Enums\MetricResultStatus;
use App\Models\Analysis;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use Database\Seeders\CategoryDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoryComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategoryDefinitionSeeder::class);
    }

    public function test_automatically_selects_the_most_recent_completed_previous_analysis(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $website = Website::factory()->for($project)->create();

        $older = Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Completed, 'completed_at' => now()->subDays(10)]);
        WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $older->id, 'website_id' => $website->id]);

        $middle = Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Completed, 'completed_at' => now()->subDays(5)]);
        WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $middle->id, 'website_id' => $website->id]);

        $latest = Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Completed, 'completed_at' => now()]);
        WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $latest->id, 'website_id' => $website->id]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$latest->id}/history-comparison");

        $response->assertOk();
        $response->assertJsonPath('data.previous.id', $middle->id);
    }

    public function test_explicit_previous_analysis_id_is_honored(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $website = Website::factory()->for($project)->create();

        $older = Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Completed, 'completed_at' => now()->subDays(10)]);
        WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $older->id, 'website_id' => $website->id]);

        $latest = Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Completed, 'completed_at' => now()]);
        WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $latest->id, 'website_id' => $website->id]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$latest->id}/history-comparison?previous_analysis_id={$older->id}");

        $response->assertOk();
        $response->assertJsonPath('data.previous.id', $older->id);
    }

    public function test_returns_no_previous_when_none_exists(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $analysis = Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Completed]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/history-comparison");

        $response->assertOk();
        $response->assertJsonPath('data.previous', null);
        $response->assertJsonPath('data.sites', []);
    }

    public function test_rejects_previous_analysis_from_another_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $otherProject = Project::factory()->for($user)->create();

        $analysis = Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Completed]);
        $otherAnalysis = Analysis::factory()->for($otherProject)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Completed]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/history-comparison?previous_analysis_id={$otherAnalysis->id}");

        $response->assertStatus(422);
    }

    public function test_detects_improved_and_new_metrics_between_analyses(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $website = Website::factory()->for($project)->create();

        $definition = MetricDefinition::factory()->create([
            'key' => 'title_present', 'category_key' => 'technical_seo', 'scoring_type' => 'boolean', 'max_score' => 8, 'higher_is_better' => true,
        ]);

        $previous = Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Completed, 'completed_at' => now()->subDay()]);
        $previousWa = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $previous->id, 'website_id' => $website->id]);
        MetricResult::factory()->create(['website_analysis_id' => $previousWa->id, 'metric_definition_id' => $definition->id, 'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => false]]);

        $current = Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Completed, 'completed_at' => now()]);
        $currentWa = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $current->id, 'website_id' => $website->id]);
        MetricResult::factory()->create(['website_analysis_id' => $currentWa->id, 'metric_definition_id' => $definition->id, 'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => true]]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$current->id}/history-comparison");

        $response->assertOk();
        $sites = collect($response->json('data.sites'));
        $site = $sites->firstWhere('website_id', $website->id);

        $this->assertNotNull($site);
        $this->assertTrue($site['present_in_current']);
        $this->assertTrue($site['present_in_previous']);
        $metricDelta = collect($site['metric_deltas'])->firstWhere('key', 'title_present');
        $this->assertSame('improved', $metricDelta['classification']);
    }

    public function test_shows_a_site_present_only_in_the_current_analysis(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $existingWebsite = Website::factory()->for($project)->create();
        $newWebsite = Website::factory()->for($project)->create();

        $previous = Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Completed, 'completed_at' => now()->subDay()]);
        WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $previous->id, 'website_id' => $existingWebsite->id]);

        $current = Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Completed, 'completed_at' => now()]);
        WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $current->id, 'website_id' => $existingWebsite->id]);
        WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $current->id, 'website_id' => $newWebsite->id]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$current->id}/history-comparison");

        $sites = collect($response->json('data.sites'));
        $newSite = $sites->firstWhere('website_id', $newWebsite->id);

        $this->assertTrue($newSite['present_in_current']);
        $this->assertFalse($newSite['present_in_previous']);
        $this->assertNull($newSite['overall_score_delta']);
    }
}
