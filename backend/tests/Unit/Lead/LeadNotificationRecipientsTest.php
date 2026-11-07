<?php

namespace Tests\Unit\Lead;

use App\Mail\BrandWheelAnalysisCompletedMail;
use App\Models\BrandWheelAnalysisResult;
use App\Services\Lead\LeadNotificationService;
use App\Support\LeadNotificationRecipients;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 依頼DO: 社内通知の宛先を複数にできる(LEAD_NOTIFICATION_TO をカンマ区切り)。返信先は先頭の1件だけ。
 */
class LeadNotificationRecipientsTest extends TestCase
{
    public function test_addresses_are_split_by_comma_and_invalid_or_duplicate_ones_are_dropped(): void
    {
        config(['lead.notification_to' => ' shared@example.com, me@example.org ;shared@example.com, not-an-address ']);

        $this->assertSame(['shared@example.com', 'me@example.org'], LeadNotificationRecipients::all());
        $this->assertSame('shared@example.com', LeadNotificationRecipients::replyTo());

        config(['lead.notification_to' => 'staff@example.com']);
        $this->assertSame(['staff@example.com'], LeadNotificationRecipients::all(), '1件のときは従来どおり');

        config(['lead.notification_to' => null]);
        $this->assertSame([], LeadNotificationRecipients::all());
        $this->assertNull(LeadNotificationRecipients::replyTo());
    }

    public function test_the_brand_wheel_staff_mail_goes_to_every_address(): void
    {
        config(['lead.notification_to' => 'shared@example.com,me@example.org']);
        Mail::fake();
        $result = new BrandWheelAnalysisResult([
            'id' => 1, 'status' => 'insufficient_input', 'axes' => null, 'axis_state_counts' => null, 'core_value_readable' => false,
            'core_value_evidence' => null, 'quality_dimension_notes' => [], 'cautions' => [], 'source_pages' => [],
        ]);

        $this->assertTrue(app(LeadNotificationService::class)->notifyBrandWheelAnalysisCompleted($result, '株式会社サンプル', '山田太郎', 'https://example.com'));

        Mail::assertSent(BrandWheelAnalysisCompletedMail::class, fn (BrandWheelAnalysisCompletedMail $mail) => $mail->hasTo('shared@example.com') && $mail->hasTo('me@example.org'));
    }
}
