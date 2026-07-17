<?php

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisStatus;
use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\Analysis;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StartAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_start_an_analysis_for_their_project(): void
    {
        Queue::fake([StartAnalysisJob::class]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Website::factory()->for($project)->create();

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/analyses");

        $response->assertCreated();
        $response->assertJsonPath('data.status', AnalysisStatus::Pending->value);
        $this->assertDatabaseHas('analyses', ['project_id' => $project->id, 'created_by' => $user->id]);
        $this->assertDatabaseCount('website_analyses', 1);

        Queue::assertPushed(StartAnalysisJob::class);
    }

    public function test_starting_analysis_fails_when_project_has_no_websites(): void
    {
        Queue::fake([StartAnalysisJob::class]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/analyses");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('website_ids');
    }

    public function test_user_cannot_start_analysis_for_another_users_project(): void
    {
        Queue::fake([StartAnalysisJob::class]);

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        Website::factory()->for($project)->create();

        $response = $this->actingAs($other)->postJson("/api/projects/{$project->id}/analyses");

        $response->assertStatus(403);
    }

    public function test_website_id_from_another_project_is_rejected(): void
    {
        Queue::fake([StartAnalysisJob::class]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Website::factory()->for($project)->create();

        $otherProject = Project::factory()->for($user)->create();
        $foreignWebsite = Website::factory()->for($otherProject)->create();

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/analyses", [
            'website_ids' => [$foreignWebsite->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('website_ids');
    }

    public function test_more_than_five_website_ids_is_rejected(): void
    {
        Queue::fake([StartAnalysisJob::class]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $websites = Website::factory()->for($project)->count(6)->create();

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/analyses", [
            'website_ids' => $websites->pluck('id')->all(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('website_ids');
    }

    public function test_cannot_start_analysis_while_one_is_already_running(): void
    {
        Queue::fake([StartAnalysisJob::class]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Website::factory()->for($project)->create();

        Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Running]);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/analyses");

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'ANALYSIS_ALREADY_RUNNING');
    }

    public function test_unauthenticated_user_cannot_start_analysis(): void
    {
        $project = Project::factory()->create();

        $response = $this->postJson("/api/projects/{$project->id}/analyses");

        $response->assertStatus(401);
    }
}
