<?php

namespace Tests\Feature\Lead;

use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\Analysis;
use App\Models\LeadSession;
use App\Notifications\Lead\LeadConsultationRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LeadConsultationTest extends TestCase
{
    use RefreshDatabase;

    private function issueTokenAndAnalysis(): array
    {
        $onboarding = $this->postJson('/api/lead/onboarding', [
            'company_name' => '株式会社サンプル',
            'contact_name' => '山田太郎',
            'email' => 'lead@example.com',
            'privacy_policy_agreed' => true,
        ]);
        $token = $onboarding->json('data.token');

        Queue::fake([StartAnalysisJob::class]);
        $started = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com']);

        return [$token, $started->json('data.analysis_id')];
    }

    public function test_first_request_sends_the_notification_and_reports_not_already_requested(): void
    {
        config(['lead.notification_to' => 'staff@example.com']);
        Notification::fake();
        [$token, $analysisId] = $this->issueTokenAndAnalysis();

        $response = $this->postJson("/api/lead/analyses/{$analysisId}/consultation?token={$token}");

        $response->assertOk();
        $response->assertJsonPath('data.already_requested', false);
        Notification::assertSentOnDemand(LeadConsultationRequestedNotification::class);
        $this->assertNotNull(LeadSession::first()->consultation_requested_at);
    }

    public function test_a_second_request_does_not_send_a_duplicate_notification(): void
    {
        config(['lead.notification_to' => 'staff@example.com']);
        Notification::fake();
        [$token, $analysisId] = $this->issueTokenAndAnalysis();

        $this->postJson("/api/lead/analyses/{$analysisId}/consultation?token={$token}");
        $second = $this->postJson("/api/lead/analyses/{$analysisId}/consultation?token={$token}");

        $second->assertOk();
        $second->assertJsonPath('data.already_requested', true);
        Notification::assertSentOnDemandTimes(LeadConsultationRequestedNotification::class, 1);
    }

    public function test_notification_is_skipped_without_error_when_no_recipient_is_configured(): void
    {
        config(['lead.notification_to' => null]);
        Notification::fake();
        [$token, $analysisId] = $this->issueTokenAndAnalysis();

        $response = $this->postJson("/api/lead/analyses/{$analysisId}/consultation?token={$token}");

        $response->assertOk();
        Notification::assertNothingSent();
    }

    public function test_a_consultation_request_for_an_analysis_owned_by_a_different_lead_session_returns_404(): void
    {
        config(['lead.notification_to' => 'staff@example.com']);
        [, $analysisId] = $this->issueTokenAndAnalysis();

        $otherOnboarding = $this->postJson('/api/lead/onboarding', [
            'company_name' => '別の会社',
            'contact_name' => '鈴木花子',
            'email' => 'other@example.com',
            'privacy_policy_agreed' => true,
        ]);
        $otherToken = $otherOnboarding->json('data.token');

        $response = $this->postJson("/api/lead/analyses/{$analysisId}/consultation?token={$otherToken}");

        $response->assertNotFound();
    }

    public function test_repeated_requests_beyond_the_per_minute_limit_are_throttled(): void
    {
        config(['lead.notification_to' => 'staff@example.com']);
        Notification::fake();
        [$token, $analysisId] = $this->issueTokenAndAnalysis();

        // lead-consultationは3回/分の制限(RateLimiter::for('lead-consultation'))。
        for ($i = 0; $i < 3; $i++) {
            $this->postJson("/api/lead/analyses/{$analysisId}/consultation?token={$token}")->assertOk();
        }

        $fourth = $this->postJson("/api/lead/analyses/{$analysisId}/consultation?token={$token}");

        $fourth->assertStatus(429);
    }
}
