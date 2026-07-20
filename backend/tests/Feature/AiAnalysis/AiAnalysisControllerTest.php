<?php

namespace Tests\Feature\AiAnalysis;

use App\Enums\AnalysisStatus;
use App\Models\AiAnalysisResult;
use App\Models\Analysis;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AiAnalysisControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsiteAnalysis(User $user): WebsiteAnalysis
    {
        $project = Project::factory()->for($user)->create();
        $website = Website::factory()->for($project)->create();
        $analysis = Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => AnalysisStatus::Completed]);

        return WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
    }

    public function test_show_returns_null_when_no_ai_analysis_exists_yet(): void
    {
        $user = User::factory()->create();
        $websiteAnalysis = $this->makeWebsiteAnalysis($user);

        $response = $this->actingAs($user)->getJson("/api/website-analyses/{$websiteAnalysis->id}/ai-analysis");

        $response->assertOk();
        $response->assertJsonPath('data', null);
    }

    public function test_show_returns_the_latest_result(): void
    {
        $user = User::factory()->create();
        $websiteAnalysis = $this->makeWebsiteAnalysis($user);
        AiAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'success', 'provider' => 'mock', 'is_mock' => true,
        ]);

        $response = $this->actingAs($user)->getJson("/api/website-analyses/{$websiteAnalysis->id}/ai-analysis");

        $response->assertOk();
        $response->assertJsonPath('data.provider', 'mock');
        $response->assertJsonPath('data.is_mock', true);
    }

    public function test_store_dispatches_a_job_and_creates_a_pending_record(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $websiteAnalysis = $this->makeWebsiteAnalysis($user);

        $response = $this->actingAs($user)->postJson("/api/website-analyses/{$websiteAnalysis->id}/ai-analysis");

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'pending');
        Bus::assertDispatched(\App\Jobs\GenerateAiAnalysisJob::class);
        $this->assertSame(1, AiAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }

    public function test_store_rejects_duplicate_execution_while_already_running(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $websiteAnalysis = $this->makeWebsiteAnalysis($user);
        AiAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'running',
        ]);

        $response = $this->actingAs($user)->postJson("/api/website-analyses/{$websiteAnalysis->id}/ai-analysis");

        $response->assertStatus(409);
        Bus::assertNotDispatched(\App\Jobs\GenerateAiAnalysisJob::class);
    }

    public function test_store_requires_confirmation_to_regenerate_an_existing_result(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $websiteAnalysis = $this->makeWebsiteAnalysis($user);
        AiAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'success', 'created_at' => now()->subDays(1),
        ]);

        $response = $this->actingAs($user)->postJson("/api/website-analyses/{$websiteAnalysis->id}/ai-analysis");

        $response->assertStatus(409);
        $response->assertJsonPath('meta.needs_confirmation', true);
        Bus::assertNotDispatched(\App\Jobs\GenerateAiAnalysisJob::class);
    }

    public function test_store_proceeds_when_confirmed(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $websiteAnalysis = $this->makeWebsiteAnalysis($user);
        AiAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'success', 'created_at' => now()->subDays(1),
        ]);

        $response = $this->actingAs($user)->postJson("/api/website-analyses/{$websiteAnalysis->id}/ai-analysis", ['confirm' => true]);

        $response->assertStatus(202);
        Bus::assertDispatched(\App\Jobs\GenerateAiAnalysisJob::class);
    }

    public function test_store_enforces_a_cooldown_immediately_after_a_recent_result(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $websiteAnalysis = $this->makeWebsiteAnalysis($user);
        AiAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'success', 'created_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson("/api/website-analyses/{$websiteAnalysis->id}/ai-analysis", ['confirm' => true]);

        $response->assertStatus(429);
        Bus::assertNotDispatched(\App\Jobs\GenerateAiAnalysisJob::class);
    }

    public function test_other_user_cannot_view_or_trigger_ai_analysis(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $websiteAnalysis = $this->makeWebsiteAnalysis($owner);

        $this->actingAs($other)->getJson("/api/website-analyses/{$websiteAnalysis->id}/ai-analysis")->assertStatus(403);
        $this->actingAs($other)->postJson("/api/website-analyses/{$websiteAnalysis->id}/ai-analysis")->assertStatus(403);
    }
}
