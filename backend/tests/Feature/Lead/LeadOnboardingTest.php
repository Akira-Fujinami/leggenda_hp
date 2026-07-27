<?php

namespace Tests\Feature\Lead;

use App\Models\LeadSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => '株式会社サンプル',
            'contact_name' => '山田太郎',
            'email' => 'lead@example.com',
            'phone' => '03-1234-5678',
            'industry' => '製造業',
            'employee_range' => '50-100',
            'privacy_policy_agreed' => true,
        ], $overrides);
    }

    public function test_unauthenticated_user_can_submit_the_lead_form_and_receive_a_token(): void
    {
        $response = $this->postJson('/api/lead/onboarding', $this->validPayload());

        $response->assertCreated();
        $response->assertJsonStructure(['data' => ['token', 'expires_at']]);
        $this->assertDatabaseCount('lead_sessions', 1);

        $session = LeadSession::first();
        $this->assertSame('lead@example.com', $session->email);
        $this->assertSame(0, $session->analyses_used);
    }

    public function test_privacy_policy_agreement_is_required(): void
    {
        $response = $this->postJson('/api/lead/onboarding', $this->validPayload(['privacy_policy_agreed' => false]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['privacy_policy_agreed']);
    }

    public function test_email_is_required_and_validated(): void
    {
        $response = $this->postJson('/api/lead/onboarding', $this->validPayload(['email' => 'not-an-email']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_resubmitting_the_same_email_reuses_the_existing_session_and_preserves_analyses_used(): void
    {
        $this->postJson('/api/lead/onboarding', $this->validPayload())->assertCreated();

        $session = LeadSession::first();
        $session->update(['analyses_used' => 1]);

        $secondResponse = $this->postJson('/api/lead/onboarding', $this->validPayload(['contact_name' => '佐藤花子']));
        $secondResponse->assertCreated();

        $this->assertDatabaseCount('lead_sessions', 1);
        $session->refresh();
        $this->assertSame(1, $session->analyses_used, '既存セッションのanalyses_usedはフォーム再送信で0に戻ってはいけない');
        $this->assertSame('佐藤花子', $session->contact_name, '会社名・担当者名は最新の入力へ更新されてよい');
    }

    public function test_the_two_tokens_from_reuse_are_different_so_the_old_link_is_invalidated(): void
    {
        $firstToken = $this->postJson('/api/lead/onboarding', $this->validPayload())->json('data.token');
        $secondToken = $this->postJson('/api/lead/onboarding', $this->validPayload())->json('data.token');

        $this->assertNotSame($firstToken, $secondToken);
    }

    public function test_form_submission_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/lead/onboarding', $this->validPayload(['email' => "lead{$i}@example.com"]))->assertCreated();
        }

        $this->postJson('/api/lead/onboarding', $this->validPayload(['email' => 'lead-over-limit@example.com']))
            ->assertStatus(429);
    }

    public function test_token_hash_is_stored_not_the_plain_token(): void
    {
        $response = $this->postJson('/api/lead/onboarding', $this->validPayload());
        $plainToken = $response->json('data.token');

        $session = LeadSession::first();
        $this->assertNotSame($plainToken, $session->token_hash);
        $this->assertSame(hash('sha256', $plainToken), $session->token_hash);
    }
}
