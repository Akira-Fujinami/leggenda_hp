<?php

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\AnalysisStatus;
use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Enums\WebsiteAnalysisStatus;
use App\Models\Analysis;
use App\Models\AnalysisJob;
use App\Models\CategoryDefinition;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use Database\Seeders\CategoryDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * partial/running/failedのAnalysisでも、取得済みのデータがあれば
 * results APIが200を返すことを保証する回帰テスト。
 * (Analysis ID 50で発生した「結果画面がNot Found」報告を受けて追加。
 * 実際の原因はフロントエンドのdevサーバープロセスの破損であり、backendの
 * 挙動自体は元から正しかったが、将来の劣化を防ぐために明示的にテスト化する)
 */
class AnalysisResultsAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeAnalysis(User $user, AnalysisStatus $status): Analysis
    {
        $project = Project::factory()->for($user)->create();

        return Analysis::factory()->for($project)->create(['created_by' => $user->id, 'status' => $status]);
    }

    public function test_completed_results_returns_200(): void
    {
        $user = User::factory()->create();
        $analysis = $this->makeAnalysis($user, AnalysisStatus::Completed);
        WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => Website::factory()->for($analysis->project)->create()->id]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/results");

        $response->assertOk();
    }

    public function test_partial_results_returns_200_with_available_data(): void
    {
        $user = User::factory()->create();
        $analysis = $this->makeAnalysis($user, AnalysisStatus::Partial);
        $website = Website::factory()->for($analysis->project)->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->create([
            'analysis_id' => $analysis->id, 'website_id' => $website->id, 'status' => WebsiteAnalysisStatus::Partial, 'progress' => 100,
        ]);

        $this->seed(CategoryDefinitionSeeder::class);
        $definition = MetricDefinition::factory()->create(['key' => 'title_present', 'category_key' => 'technical_seo', 'scoring_type' => 'boolean', 'max_score' => 8]);
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => true],
        ]);

        AnalysisJob::factory()->create([
            'analysis_id' => $analysis->id, 'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => JobType::CaptureScreenshotDesktop, 'status' => AnalysisJobStatus::Failed, 'error_code' => 'SCREENSHOT_FAILED',
        ]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/results");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'partial');
        $response->assertJsonPath('data.websites.0.status', 'partial');
        $response->assertJsonPath('data.websites.0.score.metric_summary.success', 1);
    }

    public function test_running_analysis_with_partial_data_returns_200(): void
    {
        $user = User::factory()->create();
        $analysis = $this->makeAnalysis($user, AnalysisStatus::Running);
        $website = Website::factory()->for($analysis->project)->create();
        WebsiteAnalysis::factory()->create([
            'analysis_id' => $analysis->id, 'website_id' => $website->id, 'status' => WebsiteAnalysisStatus::Running, 'progress' => 40,
        ]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/results");

        $response->assertOk();
    }

    public function test_failed_analysis_with_some_available_data_returns_200(): void
    {
        $user = User::factory()->create();
        $analysis = $this->makeAnalysis($user, AnalysisStatus::Failed);
        $website = Website::factory()->for($analysis->project)->create();
        WebsiteAnalysis::factory()->create([
            'analysis_id' => $analysis->id, 'website_id' => $website->id, 'status' => WebsiteAnalysisStatus::Failed, 'progress' => 20,
        ]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/results");

        $response->assertOk();
    }

    public function test_zero_screenshots_does_not_break_the_response(): void
    {
        $user = User::factory()->create();
        $analysis = $this->makeAnalysis($user, AnalysisStatus::Partial);
        $website = Website::factory()->for($analysis->project)->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->create([
            'analysis_id' => $analysis->id, 'website_id' => $website->id, 'status' => WebsiteAnalysisStatus::Partial, 'progress' => 100,
        ]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/results");

        $response->assertOk();
        $response->assertJsonPath('data.websites.0.screenshots', []);
    }

    public function test_no_rendered_html_or_pages_does_not_break_the_response(): void
    {
        $user = User::factory()->create();
        $analysis = $this->makeAnalysis($user, AnalysisStatus::Partial);
        $website = Website::factory()->for($analysis->project)->create();
        WebsiteAnalysis::factory()->create([
            'analysis_id' => $analysis->id, 'website_id' => $website->id, 'status' => WebsiteAnalysisStatus::Partial, 'progress' => 100,
        ]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/results");

        $response->assertOk();
        $response->assertJsonPath('data.websites.0.seo', null);
    }

    public function test_external_seo_unavailable_does_not_break_the_response(): void
    {
        $user = User::factory()->create();
        $analysis = $this->makeAnalysis($user, AnalysisStatus::Partial);
        $website = Website::factory()->for($analysis->project)->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->create([
            'analysis_id' => $analysis->id, 'website_id' => $website->id, 'status' => WebsiteAnalysisStatus::Partial, 'progress' => 100,
        ]);

        $this->seed(CategoryDefinitionSeeder::class);
        $definition = MetricDefinition::factory()->create(['key' => 'authority_score', 'category_key' => 'authority', 'scoring_type' => 'not_scored']);
        MetricResult::factory()->unavailable()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $definition->id, 'error_code' => 'SEMRUSH_NOT_CONFIGURED',
        ]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/results");

        $response->assertOk();
    }

    public function test_partial_metric_results_returns_200(): void
    {
        $user = User::factory()->create();
        $analysis = $this->makeAnalysis($user, AnalysisStatus::Partial);
        $website = Website::factory()->for($analysis->project)->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->create([
            'analysis_id' => $analysis->id, 'website_id' => $website->id, 'status' => WebsiteAnalysisStatus::Partial, 'progress' => 100,
        ]);

        $this->seed(CategoryDefinitionSeeder::class);
        $definition = MetricDefinition::factory()->create(['key' => 'title_present', 'category_key' => 'technical_seo', 'scoring_type' => 'boolean']);
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => true],
        ]);

        $response = $this->actingAs($user)->getJson("/api/analyses/{$analysis->id}/results");

        $response->assertOk();
    }

    public function test_nonexistent_analysis_returns_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/analyses/999999/results');

        $response->assertStatus(404);
    }

    public function test_other_users_analysis_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $analysis = $this->makeAnalysis($owner, AnalysisStatus::Completed);

        $response = $this->actingAs($other)->getJson("/api/analyses/{$analysis->id}/results");

        $response->assertStatus(403);
    }
}
