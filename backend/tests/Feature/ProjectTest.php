<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_list_projects(): void
    {
        $response = $this->getJson('/api/projects');

        $response->assertStatus(401);
    }

    public function test_project_list_returns_200_with_empty_array_when_user_has_no_projects(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/projects');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_project_list_logs_structured_context_and_returns_a_safe_response_on_database_exception(): void
    {
        // production相当(APP_DEBUG=false)でSQLやスタックトレースがブラウザへ
        // 漏れないことを確認する。テスト環境の既定はAPP_DEBUG=trueのため明示的に上書きする。
        Config::set('app.debug', false);
        Log::spy();

        $user = User::factory()->create();

        // projectsテーブルを一時的に落として、DB例外発生時の挙動を検証する
        // (RefreshDatabaseがテストをトランザクションで包んでおり、テスト終了後は元に戻る)。
        Schema::drop('projects');

        $response = $this->actingAs($user)->getJson('/api/projects');

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('no such table', $response->getContent());
        $this->assertStringNotContainsString('relation "projects"', $response->getContent());

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($user) {
                $contextJson = json_encode($context);

                return $message === 'projects.index_failed'
                    && $context['endpoint'] === 'GET /api/projects'
                    && $context['user_id'] === $user->id
                    && $context['status'] === 500
                    && ! str_contains($contextJson, 'APP_KEY')
                    && ! str_contains($contextJson, $user->password);
            });
    }

    public function test_user_can_create_a_project(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/projects', [
            'name' => 'Compare Project',
            'description' => 'desc',
            'industry' => 'travel',
            'purpose' => 'compare',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Compare Project');
        $response->assertJsonPath('message', '作成しました。');
        $this->assertDatabaseHas('projects', ['user_id' => $user->id, 'name' => 'Compare Project']);
    }

    public function test_project_creation_requires_a_name(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/projects', ['name' => '']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_user_sees_only_their_own_projects_in_the_list(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Project::factory()->for($user)->count(2)->create();
        Project::factory()->for($otherUser)->count(3)->create();

        $response = $this->actingAs($user)->getJson('/api/projects');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_project_list_includes_website_count_and_pagination_meta(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        \App\Models\Website::factory()->for($project)->count(2)->create();

        $response = $this->actingAs($user)->getJson('/api/projects');

        $response->assertOk();
        $response->assertJsonPath('data.0.websites_count', 2);
        $response->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_user_can_view_their_own_project_detail_with_websites(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        \App\Models\Website::factory()->for($project)->create();

        $response = $this->actingAs($user)->getJson("/api/projects/{$project->id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data.websites');
    }

    public function test_user_cannot_view_another_users_project(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $response = $this->actingAs($stranger)->getJson("/api/projects/{$project->id}");

        $response->assertStatus(403);
        $response->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_user_can_update_their_own_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['name' => 'Old Name']);

        $response = $this->actingAs($user)->patchJson("/api/projects/{$project->id}", [
            'name' => 'New Name',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New Name');
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'New Name']);
    }

    public function test_user_cannot_update_another_users_project(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = Project::factory()->for($owner)->create(['name' => 'Old Name']);

        $response = $this->actingAs($stranger)->patchJson("/api/projects/{$project->id}", [
            'name' => 'Hacked',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Old Name']);
    }

    public function test_user_can_delete_their_own_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $response = $this->actingAs($user)->deleteJson("/api/projects/{$project->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_deleting_a_project_cascades_to_its_websites(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $website = \App\Models\Website::factory()->for($project)->create();

        $this->actingAs($user)->deleteJson("/api/projects/{$project->id}")->assertOk();

        $this->assertDatabaseMissing('websites', ['id' => $website->id]);
    }

    public function test_user_cannot_delete_another_users_project(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $response = $this->actingAs($stranger)->deleteJson("/api/projects/{$project->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }
}
