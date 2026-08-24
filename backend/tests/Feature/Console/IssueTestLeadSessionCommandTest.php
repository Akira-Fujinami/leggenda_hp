<?php

namespace Tests\Feature\Console;

use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\LeadSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IssueTestLeadSessionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_run_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->artisan('lead:issue-test-session', ['self_url' => 'https://example.com'])
                ->assertFailed();
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }

        $this->assertSame(0, LeadSession::query()->count());
    }

    public function test_it_creates_a_valid_lead_session_and_starts_an_analysis(): void
    {
        Queue::fake([StartAnalysisJob::class]);

        $this->artisan('lead:issue-test-session', [
            'self_url' => 'https://example.com',
            'competitor_url' => 'https://competitor.example.com',
        ])->assertSuccessful();

        $this->assertSame(1, LeadSession::query()->count());
        $session = LeadSession::first();

        $this->assertStringEndsWith('@example.com', $session->email);
        // 2026-08-22: 実行回数の消費は自社サイトの本文取得成功時点
        // (GenerateBrandWheelAnalysisJob)へ後ろにずらされたため、StartAnalysisJob
        // をfakeしたこのテストの時点ではまだ消費されていない
        // (LeadAnalysisTest::test_valid_token_starts_an_analysis_for_self_site_onlyと同じ理由)。
        $this->assertSame(0, $session->analyses_used);
        $this->assertSame(1, $session->projects()->count());
    }

    public function test_it_always_creates_a_new_session_even_when_run_repeatedly(): void
    {
        Queue::fake([StartAnalysisJob::class]);

        $this->artisan('lead:issue-test-session', ['self_url' => 'https://example.com'])->assertSuccessful();
        $this->artisan('lead:issue-test-session', ['self_url' => 'https://example.com'])->assertSuccessful();

        $this->assertSame(2, LeadSession::query()->count());
        $emails = LeadSession::query()->pluck('email');
        $this->assertNotSame($emails[0], $emails[1]);
    }
}
