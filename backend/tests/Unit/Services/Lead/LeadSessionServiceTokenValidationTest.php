<?php

namespace Tests\Unit\Services\Lead;

use App\Models\LeadSession;
use App\Services\Lead\LeadSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 2026-07-29のユーザー指摘への対応の回帰テスト: 画面には「該当なし」
 * 「期限切れ」を区別して見せない(トークンの存在有無を推測されないため)が、
 * サーバーログでは区別して記録し、かつトークン値そのものは記録しない。
 */
class LeadSessionServiceTokenValidationTest extends TestCase
{
    use RefreshDatabase;

    private function service(): LeadSessionService
    {
        return app(LeadSessionService::class);
    }

    public function test_a_token_that_matches_no_session_is_logged_as_not_found(): void
    {
        Log::spy();

        $result = $this->service()->findValidByToken('this-token-does-not-exist-anywhere');

        $this->assertNull($result);
        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context = []) {
                return $message === 'Lead token validation failed: not found'
                    && ! str_contains(json_encode($context), 'this-token-does-not-exist-anywhere');
            });
    }

    public function test_an_expired_token_is_logged_as_expired_with_the_session_id_but_not_the_token(): void
    {
        $rawToken = 'a-real-looking-token-value-1234567890';
        $session = LeadSession::factory()->create([
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->subDay(),
        ]);

        Log::spy();

        $result = $this->service()->findValidByToken($rawToken);

        $this->assertNull($result);
        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context = []) use ($session, $rawToken) {
                $contextJson = json_encode($context);

                return $message === 'Lead token validation failed: expired'
                    && ($context['lead_session_id'] ?? null) === $session->id
                    && ! str_contains($contextJson, $rawToken);
            });
    }

    public function test_a_valid_unexpired_token_logs_nothing(): void
    {
        $rawToken = 'a-valid-token-value';
        LeadSession::factory()->create([
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addDay(),
        ]);

        Log::spy();

        $result = $this->service()->findValidByToken($rawToken);

        $this->assertNotNull($result);
        Log::shouldNotHaveReceived('info');
    }
}
