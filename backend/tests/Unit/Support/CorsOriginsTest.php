<?php

namespace Tests\Unit\Support;

use App\Support\CorsOrigins;
use PHPUnit\Framework\TestCase;

class CorsOriginsTest extends TestCase
{
    public function test_frontend_url_alone_is_included(): void
    {
        $this->assertSame(
            ['https://app.example.com'],
            CorsOrigins::resolve('https://app.example.com', null)
        );
    }

    public function test_extra_cors_allowed_origins_are_appended(): void
    {
        $this->assertSame(
            ['https://app.example.com', 'https://staging.example.com'],
            CorsOrigins::resolve('https://app.example.com', 'https://staging.example.com')
        );
    }

    public function test_multiple_extra_origins_are_comma_separated(): void
    {
        $this->assertSame(
            ['https://app.example.com', 'https://staging.example.com', 'https://preview.example.com'],
            CorsOrigins::resolve(
                'https://app.example.com',
                'https://staging.example.com,https://preview.example.com'
            )
        );
    }

    public function test_empty_entries_trailing_slashes_and_duplicates_are_normalized(): void
    {
        $result = CorsOrigins::resolve(
            'https://app.example.com/',
            'https://app.example.com, ,https://staging.example.com/,https://staging.example.com'
        );

        $this->assertSame(
            ['https://app.example.com', 'https://staging.example.com'],
            $result
        );
    }

    public function test_wildcard_is_never_included(): void
    {
        $result = CorsOrigins::resolve('*', '*,https://app.example.com');

        $this->assertSame(['https://app.example.com'], $result);
        $this->assertNotContains('*', $result);
    }

    public function test_null_frontend_url_and_null_extra_origins_resolve_to_empty_array(): void
    {
        $this->assertSame([], CorsOrigins::resolve(null, null));
    }

    public function test_assert_configured_throws_when_empty_in_production(): void
    {
        $this->expectException(\RuntimeException::class);

        CorsOrigins::assertConfigured([], 'production');
    }

    public function test_assert_configured_throws_when_empty_in_an_arbitrary_named_environment(): void
    {
        // ステージング等、local/testing以外の環境名は全てproduction相当に扱う。
        $this->expectException(\RuntimeException::class);

        CorsOrigins::assertConfigured([], 'staging');
    }

    public function test_assert_configured_allows_empty_in_local(): void
    {
        CorsOrigins::assertConfigured([], 'local');
        $this->addToAssertionCount(1);
    }

    public function test_assert_configured_allows_empty_in_testing(): void
    {
        CorsOrigins::assertConfigured([], 'testing');
        $this->addToAssertionCount(1);
    }

    public function test_assert_configured_passes_when_origins_are_present_in_production(): void
    {
        CorsOrigins::assertConfigured(['https://app.example.com'], 'production');
        $this->addToAssertionCount(1);
    }
}
