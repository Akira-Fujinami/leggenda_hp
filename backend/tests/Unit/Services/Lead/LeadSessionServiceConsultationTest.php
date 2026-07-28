<?php

namespace Tests\Unit\Services\Lead;

use App\Models\LeadSession;
use App\Services\Lead\LeadSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 相談リクエストの二重送信防止(条件付きUPDATEによる一度きりの勝者決定)の回帰テスト。
 */
class LeadSessionServiceConsultationTest extends TestCase
{
    use RefreshDatabase;

    private function service(): LeadSessionService
    {
        return app(LeadSessionService::class);
    }

    public function test_first_request_wins_and_sets_the_timestamp(): void
    {
        $session = LeadSession::factory()->create(['consultation_requested_at' => null]);

        $isFirstRequest = $this->service()->recordConsultationRequested($session);

        $this->assertTrue($isFirstRequest);
        $this->assertNotNull($session->fresh()->consultation_requested_at);
    }

    public function test_second_request_is_reported_as_already_requested_and_does_not_move_the_timestamp(): void
    {
        $session = LeadSession::factory()->create(['consultation_requested_at' => null]);

        $this->assertTrue($this->service()->recordConsultationRequested($session));
        $firstTimestamp = $session->fresh()->consultation_requested_at;

        $this->travel(5)->minutes();
        $isSecondRequestFirst = $this->service()->recordConsultationRequested($session);

        $this->assertFalse($isSecondRequestFirst);
        $this->assertTrue($session->fresh()->consultation_requested_at->eq($firstTimestamp));
    }

    public function test_a_session_that_already_had_consultation_requested_before_this_call_is_never_treated_as_first(): void
    {
        $session = LeadSession::factory()->create(['consultation_requested_at' => now()->subDay()]);

        $isFirstRequest = $this->service()->recordConsultationRequested($session);

        $this->assertFalse($isFirstRequest);
    }
}
