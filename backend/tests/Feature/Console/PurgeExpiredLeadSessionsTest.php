<?php

namespace Tests\Feature\Console;

use App\Enums\ReportGenerationStatus;
use App\Models\Analysis;
use App\Models\LeadSession;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeExpiredLeadSessionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeExpiredSessionWithProject(): LeadSession
    {
        $session = LeadSession::factory()->create(['expires_at' => now()->subDays(200)]);
        $user = User::factory()->create();
        $project = new Project(['name' => 'expired-lead-project']);
        $project->user_id = $user->id;
        $project->lead_session_id = $session->id;
        $project->save();

        return $session;
    }

    public function test_dry_run_by_default_does_not_delete_anything(): void
    {
        $this->makeExpiredSessionWithProject();

        $this->artisan('lead:purge-expired-sessions')->assertSuccessful();

        $this->assertDatabaseCount('lead_sessions', 1);
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_execute_with_force_deletes_expired_sessions_and_their_projects(): void
    {
        $this->makeExpiredSessionWithProject();

        $this->artisan('lead:purge-expired-sessions --execute --force')->assertSuccessful();

        $this->assertDatabaseCount('lead_sessions', 0);
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_a_session_still_within_the_retention_window_is_not_purged(): void
    {
        LeadSession::factory()->create(['expires_at' => now()->subDay()]);

        $this->artisan('lead:purge-expired-sessions --execute --force')->assertSuccessful();

        $this->assertDatabaseCount('lead_sessions', 1);
    }

    public function test_execute_with_force_also_deletes_report_files_from_storage(): void
    {
        Storage::fake('analysis');

        $session = $this->makeExpiredSessionWithProject();
        $project = $session->projects->first();
        $analysis = Analysis::factory()->create(['project_id' => $project->id]);
        Storage::disk('analysis')->put("reports/{$analysis->id}/report.pdf", '%PDF-fake');
        Report::factory()->create([
            'analysis_id' => $analysis->id,
            'format' => 'pdf',
            'storage_path' => "reports/{$analysis->id}/report.pdf",
            'status' => ReportGenerationStatus::Completed,
        ]);

        $this->artisan('lead:purge-expired-sessions --execute --force')->assertSuccessful();

        Storage::disk('analysis')->assertMissing("reports/{$analysis->id}/report.pdf");
        $this->assertDatabaseCount('reports', 0);
    }

    public function test_execute_is_refused_in_production(): void
    {
        $this->makeExpiredSessionWithProject();
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('lead:purge-expired-sessions --execute --force')->assertFailed();

        $this->assertDatabaseCount('lead_sessions', 1);
    }
}
