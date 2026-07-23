<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * config/cors.php・config/session.phpは、Dockerビルド時
 * (composer dump-autoload → artisan package:discover) にも読み込まれる。
 * ビルド時にはRenderのRuntime Environment Variables(FRONTEND_URL等)が
 * まだ存在しないため、configファイル自体が本番相当の値の欠落を理由に例外を
 * 投げると、Dockerビルドそのものが失敗してしまう(実際にこの不具合が発生した)。
 *
 * 実行時の妥当性検証はApp\Support\ProductionEnvironmentValidator
 * (php artisan app:validate-production-env)の責務とし、configファイル自体は
 * 常に副作用のない純粋な配列生成のみを行うことをここで保証する。
 */
class ConfigFilesPurityTest extends TestCase
{
    private const ENV_KEYS = [
        'APP_ENV',
        'FRONTEND_URL',
        'CORS_ALLOWED_ORIGINS',
        'SESSION_SAME_SITE',
        'SESSION_SECURE_COOKIE',
    ];

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENV_KEYS as $key) {
            $this->originalEnv[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }
        }

        parent::tearDown();
    }

    public function test_cors_config_does_not_throw_when_frontend_url_is_empty_in_production(): void
    {
        putenv('APP_ENV=production');
        putenv('FRONTEND_URL=');
        putenv('CORS_ALLOWED_ORIGINS=');

        $config = require __DIR__.'/../../../config/cors.php';

        $this->assertIsArray($config);
        $this->assertSame([], $config['allowed_origins']);
        $this->assertTrue($config['supports_credentials']);
    }

    public function test_cors_config_does_not_throw_when_wildcard_is_supplied(): void
    {
        putenv('APP_ENV=production');
        putenv('FRONTEND_URL=*');
        putenv('CORS_ALLOWED_ORIGINS=*');

        $config = require __DIR__.'/../../../config/cors.php';

        $this->assertIsArray($config);
        $this->assertNotContains('*', $config['allowed_origins']);
    }

    public function test_session_config_does_not_throw_when_same_site_none_without_secure(): void
    {
        putenv('SESSION_SAME_SITE=none');
        putenv('SESSION_SECURE_COOKIE');

        $config = require __DIR__.'/../../../config/session.php';

        $this->assertIsArray($config);
        $this->assertSame('none', $config['same_site']);
    }
}
