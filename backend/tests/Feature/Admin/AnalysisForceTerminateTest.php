<?php

namespace Tests\Feature\Admin;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 停止した(Worker停止・OOM・例外で終端処理に到達できなかった)Analysisを、
 * 管理画面から強制終了(Cancelled)できる導線。config('lead.stale_analysis_
 * after_minutes')の経過を待たずに、hasAnalysisInProgress()・isCongested()の
 * 両ガードから即座に外すための手動介入(依頼者指摘の恒久対応)。
 */
class AnalysisForceTerminateTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): static
    {
        return $this->withSession(['admin_authenticated' => true]);
    }

    private function makeAnalysis(AnalysisStatus $status): Analysis
    {
        $sentinel = User::factory()->create();
        $company = LeadCompany::factory()->create();
        $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);

        return Analysis::factory()->for($project)->create(['created_by' => $sentinel->id, 'status' => $status]);
    }

    public function test_admin_can_force_terminate_a_running_analysis(): void
    {
        Log::spy();
        $analysis = $this->makeAnalysis(AnalysisStatus::Running);

        $response = $this->asAdmin()->patch("/admin/analyses/{$analysis->id}/force-terminate");

        $response->assertRedirect();
        $analysis->refresh();
        $this->assertSame(AnalysisStatus::Cancelled, $analysis->status);
        $this->assertNotNull($analysis->failed_at);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context = []) use ($analysis) {
                return $message === 'Admin force-terminated a stuck analysis'
                    && $context['analysis_id'] === $analysis->id
                    && $context['previous_status'] === 'running'
                    && isset($context['ip']);
            });
    }

    public function test_force_terminate_works_for_pending_and_queued_too(): void
    {
        foreach ([AnalysisStatus::Pending, AnalysisStatus::Queued] as $status) {
            $analysis = $this->makeAnalysis($status);

            $this->asAdmin()->patch("/admin/analyses/{$analysis->id}/force-terminate")->assertRedirect();

            $this->assertSame(AnalysisStatus::Cancelled, $analysis->fresh()->status);
        }
    }

    public function test_force_terminate_is_a_no_op_for_an_already_terminal_analysis(): void
    {
        $analysis = $this->makeAnalysis(AnalysisStatus::Completed);

        $response = $this->asAdmin()->patch("/admin/analyses/{$analysis->id}/force-terminate");

        $response->assertRedirect();
        $this->assertSame(AnalysisStatus::Completed, $analysis->fresh()->status);
    }

    public function test_force_terminate_requires_admin_authentication(): void
    {
        $analysis = $this->makeAnalysis(AnalysisStatus::Running);

        $response = $this->patch("/admin/analyses/{$analysis->id}/force-terminate");

        $response->assertStatus(401);
        $this->assertSame(AnalysisStatus::Running, $analysis->fresh()->status);
    }

    public function test_force_terminated_analysis_no_longer_blocks_hasAnalysisInProgress_or_congestion(): void
    {
        config(['lead.max_concurrent_analyses' => 1]);

        $sentinel = User::factory()->create();
        $leadSession = \App\Models\LeadSession::factory()->create();
        $project = new Project(['name' => 'stuck']);
        $project->user_id = $sentinel->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();
        $analysis = Analysis::factory()->for($project)->create(['created_by' => $sentinel->id, 'status' => AnalysisStatus::Running]);

        $this->assertTrue(app(\App\Services\Lead\LeadSessionService::class)->hasAnalysisInProgress($leadSession));

        $this->asAdmin()->patch("/admin/analyses/{$analysis->id}/force-terminate")->assertRedirect();

        $this->assertFalse(app(\App\Services\Lead\LeadSessionService::class)->hasAnalysisInProgress($leadSession->fresh()));
    }
}
