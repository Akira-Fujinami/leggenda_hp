<?php

namespace Tests\Unit\Services\Lead;

use App\Mail\BrandWheelLeadDiagnosisCompletedMail;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\LeadSession;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Lead\LeadDiagnosisCompletedNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 依頼AS-2(2026-09-03): 「診断が完了しました」メールを送ってよいかの
 * 判定・二重送信防止ロジック(GenerateLeadReportJobから呼ばれる)。
 * 実際のWord/PDF生成は行わない(GenerateLeadReportJobTest側で別途確認済み)
 * ため、ここでは判定ロジックだけを高速に検証する。
 */
class LeadDiagnosisCompletedNotifierTest extends TestCase
{
    use RefreshDatabase;

    private function makeLeadAnalysis(?\DateTimeInterface $consultationRequestedAt = null): Analysis
    {
        $leadSession = LeadSession::factory()->create([
            'company_name' => '株式会社サンプル',
            'email' => 'lead@example.com',
            'consultation_requested_at' => $consultationRequestedAt,
        ]);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id]);
        $website = Website::factory()->create(['project_id' => $project->id, 'is_primary' => true]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'state' => 'read', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => '地域社会に貢献するという理念を掲げています。'],
                ]],
            ],
            'axis_state_counts' => ['read' => 1, 'partial' => 0, 'unread' => 5],
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ]);

        return $analysis;
    }

    public function test_sends_when_consultation_has_not_been_requested(): void
    {
        Mail::fake();
        $analysis = $this->makeLeadAnalysis(consultationRequestedAt: null);

        app(LeadDiagnosisCompletedNotifier::class)->notifyIfReady($analysis);

        Mail::assertSent(BrandWheelLeadDiagnosisCompletedMail::class, 1);
        $this->assertNotNull($analysis->fresh()->lead_diagnosis_completed_notified_at);
    }

    public function test_sends_when_consultation_has_already_been_requested(): void
    {
        Mail::fake();
        $analysis = $this->makeLeadAnalysis(consultationRequestedAt: now());

        app(LeadDiagnosisCompletedNotifier::class)->notifyIfReady($analysis);

        Mail::assertSent(BrandWheelLeadDiagnosisCompletedMail::class, 1);
    }

    public function test_does_not_send_twice_for_the_same_analysis(): void
    {
        Mail::fake();
        $analysis = $this->makeLeadAnalysis();

        $notifier = app(LeadDiagnosisCompletedNotifier::class);
        $notifier->notifyIfReady($analysis);
        $notifier->notifyIfReady($analysis->fresh());

        Mail::assertSent(BrandWheelLeadDiagnosisCompletedMail::class, 1);
    }

    public function test_does_nothing_for_an_internal_analysis_without_a_lead_session(): void
    {
        Mail::fake();
        $project = Project::factory()->create();
        $analysis = Analysis::factory()->create(['project_id' => $project->id]);

        app(LeadDiagnosisCompletedNotifier::class)->notifyIfReady($analysis);

        Mail::assertNothingSent();
        $this->assertNull($analysis->fresh()->lead_diagnosis_completed_notified_at);
    }

    public function test_does_not_send_and_does_not_claim_the_marker_when_self_brand_wheel_result_is_not_eligible(): void
    {
        Mail::fake();
        $leadSession = LeadSession::factory()->create(['email' => 'lead@example.com']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id]);
        $website = Website::factory()->create(['project_id' => $project->id, 'is_primary' => true]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'success',
            'axes' => [],
            'axis_state_counts' => ['read' => 0, 'partial' => 0, 'unread' => 6],
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ]);

        app(LeadDiagnosisCompletedNotifier::class)->notifyIfReady($analysis);

        Mail::assertNothingSent();
        // 送っていないため、再度状況が変わった場合に備えマーカーは
        // 立てたままにしない(次回呼び出しでも再判定できる)。
        $this->assertNull($analysis->fresh()->lead_diagnosis_completed_notified_at);
    }

    public function test_does_nothing_when_no_website_analysis_exists_for_the_self_site(): void
    {
        Mail::fake();
        $leadSession = LeadSession::factory()->create();
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();
        $analysis = Analysis::factory()->create(['project_id' => $project->id]);

        app(LeadDiagnosisCompletedNotifier::class)->notifyIfReady($analysis);

        Mail::assertNothingSent();
    }
}
