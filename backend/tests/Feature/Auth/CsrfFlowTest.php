<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CsrfFlowTest extends TestCase
{
    use RefreshDatabase;

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

    // 419(CSRFトークン不一致)の実HTTPレスポンスは、この場所(phpunit経由の
    // Feature test)では意図的に再現できない。LaravelのCSRF検証ミドルウェア
    // (PreventRequestForgery::handle())は
    // `runningInConsole() && runningUnitTests()` (=APP_ENV=testing かつ CLI実行)
    // のとき常にCSRF検証そのものをスキップする仕様のため
    // (vendor/laravel/framework/.../PreventRequestForgery.php)、
    // phpunit.xmlでAPP_ENV=testingを強制しているテストスイート内では
    // X-XSRF-TOKENの有無に関わらず全リクエストがCSRFチェックを素通りする。
    // そのため419応答および「419→CSRF再取得→再試行成功」のクライアント契約は
    // frontend/src/lib/api-client.test.ts 側でfetchをモックして検証している。
    // ここではSanctumのCSRF検証ミドルウェア自体が正しく組み込まれていること
    // (=/sanctum/csrf-cookie がXSRF-TOKEN/セッションCookieを発行すること)を検証する。
}
