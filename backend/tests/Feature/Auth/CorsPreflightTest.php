<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CorsPreflightTest extends TestCase
{
    public function test_preflight_from_the_configured_frontend_origin_is_allowed(): void
    {
        Config::set('cors.allowed_origins', ['https://frontend.example']);

        $response = $this->withHeaders([
            'Origin' => 'https://frontend.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,x-xsrf-token',
        ])->options('/api/login');

        $response->assertSuccessful();
        $response->assertHeader('Access-Control-Allow-Origin', 'https://frontend.example');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertStringContainsString(
            'POST',
            (string) $response->headers->get('Access-Control-Allow-Methods')
        );
    }

    public function test_preflight_from_an_extra_cors_allowed_origins_entry_is_allowed(): void
    {
        Config::set('cors.allowed_origins', ['https://frontend.example', 'https://staging.example']);

        $response = $this->withHeaders([
            'Origin' => 'https://staging.example',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/login');

        $response->assertSuccessful();
        $response->assertHeader('Access-Control-Allow-Origin', 'https://staging.example');
    }

    public function test_preflight_from_an_unlisted_origin_receives_no_allow_origin_header(): void
    {
        // fruitcake/php-cors (LaravelのHandleCorsが内部で使う実装) は、許可Originが
        // ちょうど1件だけの場合、リクエストのOriginと突き合わせず常にその1件を
        // Access-Control-Allow-Originとして返す最適化を行う(isSingleOriginAllowed())。
        // これは安全である(ブラウザ側がACAOの値と実際のページOriginを比較し、
        // 一致しなければレスポンスを読めないようブロックするため)が、
        // 「未許可Originを弾く」経路を検証するには許可Originを2件以上にする必要がある。
        Config::set('cors.allowed_origins', ['https://frontend.example', 'https://staging.example']);

        $response = $this->withHeaders([
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/login');

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_supports_credentials_is_always_true(): void
    {
        $this->assertTrue(config('cors.supports_credentials'));
    }

    public function test_wildcard_origin_is_never_configured(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));
    }
}
