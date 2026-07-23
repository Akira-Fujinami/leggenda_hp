<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsAsSpaFrontend;
use Tests\TestCase;

class CsrfFlowTest extends TestCase
{
    use RefreshDatabase;
    use InteractsAsSpaFrontend;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_csrf_cookie_endpoint_sets_xsrf_and_session_cookies(): void
    {
        $response = $this->withHeaders(['Origin' => 'http://localhost:3000'])
            ->get('/sanctum/csrf-cookie');

        $response->assertNoContent();
        $response->assertCookie('XSRF-TOKEN');

        // セッションCookie名はconfig('session.cookie')から取得し、ハードコードしない。
        $cookieNames = array_map(
            fn ($cookie) => $cookie->getName(),
            $response->headers->getCookies()
        );

        $this->assertContains(config('session.cookie'), $cookieNames);
    }

    public function test_mutation_without_csrf_token_returns_419(): void
    {
        $response = $this->withHeaders(['Origin' => 'http://localhost:3000'])
            ->postJson('/api/login', [
                'email' => 'test@example.com',
                'password' => 'password123',
            ]);

        $response->assertStatus(419);
    }

    public function test_retrying_after_refetching_the_csrf_cookie_succeeds(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // 1回目: CSRF Cookie無しで送るとCSRFトークン不一致(419)になる
        // (フロントエンドのapi-clientが419時に行う「CSRF Cookie再取得→再試行」の
        // 前半部分に相当)。
        $first = $this->withHeaders(['Origin' => 'http://localhost:3000'])
            ->postJson('/api/login', [
                'email' => 'test@example.com',
                'password' => 'password123',
            ]);

        $first->assertStatus(419);

        // 2回目: csrf-cookieを取得し、正しいX-XSRF-TOKENを添えて再試行すると成功する。
        $response = $this->jsonAsFrontend('POST', '/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.email', 'test@example.com');
    }
}
