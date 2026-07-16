<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_a_website_with_normalized_url(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/websites", [
            'name' => 'My Site',
            'url' => 'example.com',
            'is_primary' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.normalized_url', 'https://example.com');
        $response->assertJsonPath('data.is_primary', true);
        $response->assertJsonPath('data.display_order', 1);
    }

    public function test_new_website_is_appended_to_the_end_of_display_order(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Website::factory()->for($project)->create(['display_order' => 1]);
        Website::factory()->for($project)->create(['display_order' => 2]);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/websites", [
            'name' => 'Third',
            'url' => 'third.example.com',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.display_order', 3);
    }

    public function test_cannot_register_more_than_five_websites_per_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Website::factory()->for($project)->count(5)->sequence(fn ($sequence) => ['display_order' => $sequence->index])->create();

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/websites", [
            'name' => 'Sixth',
            'url' => 'sixth.example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
        $this->assertSame(5, $project->websites()->count());
    }

    public function test_cannot_register_duplicate_normalized_url_in_the_same_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Website::factory()->for($project)->create(['normalized_url' => 'https://example.com']);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/websites", [
            'name' => 'Duplicate',
            'url' => 'https://example.com/',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
    }

    public function test_cannot_register_a_second_primary_website(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Website::factory()->for($project)->create(['is_primary' => true]);

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/websites", [
            'name' => 'Second Primary',
            'url' => 'second.example.com',
            'is_primary' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('is_primary');
    }

    public function test_user_cannot_register_a_website_to_another_users_project(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $response = $this->actingAs($stranger)->postJson("/api/projects/{$project->id}/websites", [
            'name' => 'Intruder',
            'url' => 'intruder.example.com',
        ]);

        $response->assertStatus(403);
    }

    public function test_user_can_update_a_website(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $website = Website::factory()->for($project)->create(['name' => 'Old Name']);

        $response = $this->actingAs($user)->patchJson("/api/websites/{$website->id}", [
            'name' => 'New Name',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New Name');
    }

    public function test_user_can_delete_a_website(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $website = Website::factory()->for($project)->create();

        $response = $this->actingAs($user)->deleteJson("/api/websites/{$website->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('websites', ['id' => $website->id]);
    }

    public function test_user_cannot_delete_another_users_website(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $website = Website::factory()->for($project)->create();

        $response = $this->actingAs($stranger)->deleteJson("/api/websites/{$website->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('websites', ['id' => $website->id]);
    }

    #[DataProvider('rejectedUrlProvider')]
    public function test_dangerous_or_invalid_urls_are_rejected_at_registration(string $url): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/websites", [
            'name' => 'Bad',
            'url' => $url,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
    }

    public static function rejectedUrlProvider(): array
    {
        return [
            'ftp scheme' => ['ftp://example.com'],
            'localhost' => ['http://localhost'],
            'loopback ip' => ['http://127.0.0.1'],
            'docker backend service' => ['http://backend'],
            'docker postgres service' => ['http://postgres:5432'],
            'malformed url' => ['http://'],
        ];
    }
}
