<?php

namespace Tests\Unit\Lead;

use App\Mail\BrandWheelAnalysisCompletedMail;
use App\Mail\BrandWheelLeadDiagnosisCompletedMail;
use App\Models\BrandWheelAnalysisResult;
use App\Services\Lead\LeadNotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LeadNotificationServiceBrandWheelTest extends TestCase
{
    private function makeResult(string $status = 'success'): BrandWheelAnalysisResult
    {
        return new BrandWheelAnalysisResult([
            'id' => 1,
            'status' => $status,
            'axes' => $status === 'success' ? [
                ['axis_key' => 'will_activity', 'state' => 'read', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => '地域社会に貢献するという理念'],
                ]],
            ] : null,
            'axis_state_counts' => $status === 'success' ? ['read' => 1, 'partial' => 0, 'unread' => 5] : null,
            'core_value_readable' => false,
            'core_value_evidence' => null,
            'quality_dimension_notes' => [],
            'cautions' => [],
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ]);
    }

    public function test_returns_false_and_logs_a_warning_when_no_recipient_is_configured(): void
    {
        config(['lead.notification_to' => null]);
        Mail::fake();
        Log::spy();

        $sent = app(LeadNotificationService::class)->notifyBrandWheelAnalysisCompleted(
            $this->makeResult(), '株式会社サンプル', '山田太郎', 'https://example.com',
        );

        $this->assertFalse($sent);
        Mail::assertNothingSent();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $message === 'Lead notification skipped: LEAD_NOTIFICATION_TO is not configured'
                && $context['event'] === 'brand_wheel_analysis_completed')
            ->once();
    }

    public function test_sends_the_mail_to_the_configured_recipient_and_returns_true(): void
    {
        config(['lead.notification_to' => 'staff@example.com']);
        Mail::fake();

        $sent = app(LeadNotificationService::class)->notifyBrandWheelAnalysisCompleted(
            $this->makeResult(), '株式会社サンプル', '山田太郎', 'https://example.com',
        );

        $this->assertTrue($sent);
        Mail::assertSent(BrandWheelAnalysisCompletedMail::class, function (BrandWheelAnalysisCompletedMail $mail) {
            return $mail->hasTo('staff@example.com') && $mail->data['companyName'] === '株式会社サンプル';
        });
    }

    public function test_insufficient_input_status_still_sends_a_mail_without_throwing(): void
    {
        config(['lead.notification_to' => 'staff@example.com']);
        Mail::fake();

        $sent = app(LeadNotificationService::class)->notifyBrandWheelAnalysisCompleted(
            $this->makeResult('insufficient_input'), '株式会社サンプル', '山田太郎', 'https://example.com',
        );

        $this->assertTrue($sent);
        Mail::assertSent(BrandWheelAnalysisCompletedMail::class, fn (BrandWheelAnalysisCompletedMail $mail) => $mail->data['insufficientInput'] === true);
    }

    // ------------------------------------------------------------------
    // 依頼AS-2(2026-09-03): 「診断が完了しました」メール(相談リクエストの
    // 有無にかかわらず送る、既存のnotifyBrandWheelAnalysisCompletedToLeadとは
    // 別のMailable)。
    // ------------------------------------------------------------------

    public function test_diagnosis_completed_sends_the_mail_to_the_lead_email(): void
    {
        Mail::fake();

        $sent = app(LeadNotificationService::class)->notifyDiagnosisCompletedToLead(
            $this->makeResult(), 'lead@example.com', 'https://example.com',
        );

        $this->assertTrue($sent);
        Mail::assertSent(BrandWheelLeadDiagnosisCompletedMail::class, function (BrandWheelLeadDiagnosisCompletedMail $mail) {
            return $mail->hasTo('lead@example.com') && $mail->data['targetUrl'] === 'https://example.com';
        });
    }

    /**
     * BrandWheelLeadEmailContentBuilder::canSend()と同じ絶対ルール
     * (6軸すべて読み取れなかった場合は社外へ送らない)をこのメソッド自身の
     * 中でも再確認する。ログにはlead_session_id相当の識別子(ここでは
     * brand_wheel_analysis_result_id)のみを残し、メールアドレス・会社名・
     * 担当者名は出さない。
     */
    public function test_diagnosis_completed_does_not_send_when_not_eligible_and_does_not_leak_pii_in_the_log(): void
    {
        Mail::fake();
        Log::spy();

        $unreadableResult = new BrandWheelAnalysisResult([
            'id' => 2,
            'status' => 'success',
            'axes' => [],
            'axis_state_counts' => ['read' => 0, 'partial' => 0, 'unread' => 6],
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ]);

        $sent = app(LeadNotificationService::class)->notifyDiagnosisCompletedToLead(
            $unreadableResult, 'lead@example.com', 'https://example.com',
        );

        $this->assertFalse($sent);
        Mail::assertNothingSent();
        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) {
                $encoded = json_encode($context);

                return $message === 'Lead diagnosis completed notification skipped: not eligible to send'
                    && $context['brand_wheel_analysis_result_id'] === 2
                    && ! str_contains($encoded, 'lead@example.com')
                    && ! str_contains($encoded, '株式会社');
            })
            ->once();
    }
}
