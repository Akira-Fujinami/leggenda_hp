<?php

namespace Tests\Unit\Lead;

use App\Mail\BrandWheelAnalysisCompletedMail;
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
}
