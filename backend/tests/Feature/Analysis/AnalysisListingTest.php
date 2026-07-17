<?php

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\AnalysisStatus;
use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Enums\WebsiteAnalysisStatus;
use App\Models\Analysis;
use App\Models\AnalysisJob;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalysisListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_analyses_for_their_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Analysis::factory()->for($project)->create(['created_by' => $user->id]);

        $response = $this->actingAs($user)->getJson("/api/projects/{$project->id}/analyses");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_user_can_view_analysis_detail(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $analysis = Analysis::factory()->for($project)->create(['created_by' => $user->id]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $analysis->id);
    }

    public function test_user_cannot_view_another_users_analysis(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $analysis = Analysis::factory()->for($project)->create(['created_by' => $owner->id]);

        $response = $this->actingAs($other)->getJson("/api/analyses/{$analysis->id}");

        $response->assertStatus(403);
    }

    public function test_progress_endpoint_returns_server_computed_progress(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $analysis = Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Running]);
        $website = Website::factory()->for($project)->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->create([
            'analysis_id' => $analysis->id,
            'website_id' => $website->id,
            'status' => WebsiteAnalysisStatus::Running,
            'progress' => 15,
        ]);
        AnalysisJob::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => JobType::FetchStaticPage,
            'status' => AnalysisJobStatus::Completed,
        ]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/progress");

        $response->assertOk();
        $response->assertJsonPath('data.websites.0.website_analysis_id', $websiteAnalysis->id);
        $response->assertJsonPath('data.websites.0.progress', 15);
        $response->assertJsonPath('data.websites.0.jobs.0.job_type', JobType::FetchStaticPage->value);
    }

    public function test_results_endpoint_includes_score_and_never_includes_raw_html(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $analysis = Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Completed]);
        $website = Website::factory()->for($project)->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->completed()->create([
            'analysis_id' => $analysis->id,
            'website_id' => $website->id,
        ]);

        $definition = MetricDefinition::query()->where('key', 'title_present')->first()
            ?? MetricDefinition::factory()->create(['key' => 'title_present', 'category' => 'technical_seo', 'max_score' => 8]);

        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success,
            'score' => 8,
            'max_score' => 8,
        ]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/results");

        $response->assertOk();
        $response->assertJsonPath('data.websites.0.website_analysis_id', $websiteAnalysis->id);
        $response->assertJsonStructure(['data' => ['websites' => [['score' => ['total_score', 'max_available_score', 'coverage_rate']]]]]);

        $raw = $response->getContent();
        $this->assertStringNotContainsString('raw_report', $raw);
        $this->assertStringNotContainsString('<html', $raw);
    }

    public function test_unauthenticated_user_cannot_view_progress(): void
    {
        $project = Project::factory()->create();
        $analysis = Analysis::factory()->for($project)->create(['created_by' => $project->user_id]);

        $response = $this->getJson("/api/analyses/{$analysis->id}/progress");

        $response->assertStatus(401);
    }
}
