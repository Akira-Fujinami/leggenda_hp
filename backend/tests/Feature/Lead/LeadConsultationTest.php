<?php

namespace Tests\Feature\Lead;

use App\Jobs\Analysis\StartAnalysisJob;
use App\Jobs\GenerateBrandWheelAnalysisJob;
use App\Mail\BrandWheelAnalysisCompletedMail;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\LeadSession;
use App\Models\WebsiteAnalysis;
use App\Notifications\Lead\LeadConsultationRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

    /**
     * issueTokenAndAnalysis()はStartAnalysisJobをfakeするが、AnalysisService::
     * start()自体(Website/WebsiteAnalysis行の作成)はfakeされないため、
     * 自社サイト(is_primary=true)のWebsiteAnalysisは既に存在する。
     * その既存の行をそのまま返す(新規に別のWebsiteを作るとproject_idの
     * 一意制約に反する)。
     */
    private function existingPrimaryWebsiteAnalysis(int $analysisId): WebsiteAnalysis
    {
        return WebsiteAnalysis::query()
            ->where('analysis_id', $analysisId)
            ->whereHas('website', fn ($q) => $q->where('is_primary', true))
            ->firstOrFail();
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

    /**
     * 2026-08-03: ブランド・ホイールの生成は診断実行時(store())に
     * AnalysisPipeline::dispatchWebsiteFanOut()から既に行われている前提。
     * 相談ボタンは新たにJobをdispatchせず、生成済みの結果を読んで
     * 2通目メールを送ってよいかを判定するだけになった。issueTokenAndAnalysis()
     * はStartAnalysisJobをfakeするため(=fan-out自体は走らない)、
     * 「診断時に生成済み」の状態はこのテストで直接BrandWheelAnalysisResultを
     * 作って再現する。
     */
    private function makeGeneratedBrandWheelResult(int $analysisId, WebsiteAnalysis $websiteAnalysis, string $status = 'success'): BrandWheelAnalysisResult
    {
        return BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysisId,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => $status,
            'is_mock' => true,
            'axes' => $status === 'success' ? [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'テスト']], 'discarded_sub_elements' => [], 'claimed_sub_element_count' => 1, 'state' => 'partial'],
            ] : null,
            'axis_state_counts' => $status === 'success' ? ['read' => 0, 'partial' => 1, 'unread' => 5] : null,
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ]);
    }

    public function test_consultation_does_not_dispatch_any_brand_wheel_generation_job(): void
    {
        config(['lead.notification_to' => 'staff@example.com']);
        Notification::fake();
        [$token, $analysisId] = $this->issueTokenAndAnalysis();
        // issueTokenAndAnalysis()内部でQueue::fake([StartAnalysisJob::class])が
        // 呼ばれ、fake対象リストが置き換わるため、必ずこの後にfakeし直す。
        Queue::fake([GenerateBrandWheelAnalysisJob::class]);
        $websiteAnalysis = $this->existingPrimaryWebsiteAnalysis($analysisId);
        $this->makeGeneratedBrandWheelResult($analysisId, $websiteAnalysis);

        $this->postJson("/api/lead/analyses/{$analysisId}/consultation?token={$token}")->assertOk();

        Queue::assertNothingPushed();
        // 1通目メールは評価結果の有無とは独立して必ず送信される。
        Notification::assertSentOnDemand(LeadConsultationRequestedNotification::class);
    }

    public function test_first_request_sends_the_brand_wheel_staff_notification_when_already_generated(): void
    {
        config(['lead.notification_to' => 'staff@example.com']);
        Notification::fake();
        Mail::fake();
        [$token, $analysisId] = $this->issueTokenAndAnalysis();
        $websiteAnalysis = $this->existingPrimaryWebsiteAnalysis($analysisId);
        $record = $this->makeGeneratedBrandWheelResult($analysisId, $websiteAnalysis);

        $this->postJson("/api/lead/analyses/{$analysisId}/consultation?token={$token}")->assertOk();

        Mail::assertSent(BrandWheelAnalysisCompletedMail::class, 1);
        $this->assertNotNull($record->fresh()->staff_notified_at);
    }

    public function test_a_second_request_does_not_send_a_duplicate_brand_wheel_notification(): void
    {
        config(['lead.notification_to' => 'staff@example.com']);
        Notification::fake();
        Mail::fake();
        [$token, $analysisId] = $this->issueTokenAndAnalysis();
        $websiteAnalysis = $this->existingPrimaryWebsiteAnalysis($analysisId);
        $this->makeGeneratedBrandWheelResult($analysisId, $websiteAnalysis);

        $this->postJson("/api/lead/analyses/{$analysisId}/consultation?token={$token}")->assertOk();
        $this->postJson("/api/lead/analyses/{$analysisId}/consultation?token={$token}")->assertOk();

        $this->assertSame(1, BrandWheelAnalysisResult::query()->count());
        Mail::assertSent(BrandWheelAnalysisCompletedMail::class, 1);
    }

    /**
     * 生成がまだ完了していない(pending)場合、相談ボタン押下時点では
     * 送らない ―― Jobの終端時に同じ判定が再度行われ、その時点で送られる
     * (BrandWheelCompletionNotifier/GenerateBrandWheelAnalysisJobTest側で
     * 検証済み)。相談受付・1通目メールには一切影響しない。
     */
    public function test_consultation_does_not_notify_yet_when_brand_wheel_generation_is_still_pending(): void
    {
        config(['lead.notification_to' => 'staff@example.com']);
        Notification::fake();
        Mail::fake();
        [$token, $analysisId] = $this->issueTokenAndAnalysis();
        $websiteAnalysis = $this->existingPrimaryWebsiteAnalysis($analysisId);
        $this->makeGeneratedBrandWheelResult($analysisId, $websiteAnalysis, status: 'pending');

        $response = $this->postJson("/api/lead/analyses/{$analysisId}/consultation?token={$token}");

        $response->assertOk();
        Notification::assertSentOnDemand(LeadConsultationRequestedNotification::class);
        Mail::assertNothingSent();
    }

    public function test_notification_still_sends_even_when_no_primary_website_analysis_exists_yet(): void
    {
        // AnalysisService::start()はWebsiteAnalysis行自体を即座に作成するため、
        // 「まだ存在しない」状態を明示的に再現する(例: 何らかの理由で該当行が
        // 削除されている等)。この場合、ブランド・ホイールの完了確認は無言で
        // スキップされるが、1通目メールの送信・レスポンスには一切影響しない。
        config(['lead.notification_to' => 'staff@example.com']);
        Notification::fake();
        [$token, $analysisId] = $this->issueTokenAndAnalysis();
        WebsiteAnalysis::query()->where('analysis_id', $analysisId)->delete();

        \Illuminate\Support\Facades\Log::spy();

        $response = $this->postJson("/api/lead/analyses/{$analysisId}/consultation?token={$token}");

        $response->assertOk();
        Notification::assertSentOnDemand(LeadConsultationRequestedNotification::class);
        $this->assertSame(0, BrandWheelAnalysisResult::query()->count());

        // データ不整合の可能性がある想定外の状態のため、運用上検知されるべき
        // 障害としてLog::errorで記録される(2026-07-29の指摘)。
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context) => $message === 'Brand wheel completion check skipped: no primary WebsiteAnalysis found'
                && $context['analysis_id'] === $analysisId)
            ->once();
    }
}
