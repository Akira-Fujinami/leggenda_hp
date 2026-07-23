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
    private array $originalGetenv = [];

    /** @var array<string, string|null> */
    private array $originalEnvSuper = [];

    /** @var array<string, string|null> */
    private array $originalServerSuper = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Laravelのenv()(Illuminate\Support\Env::getRepository())は、
        // $_SERVER → $_ENV → getenv()/putenv() の順で読む。このリポジトリは
        // プロセス内で1度だけ構築されキャッシュされるため、他のテスト
        // (実アプリを起動するFeature test)が本物のbackend/.envを既に読み込み、
        // $_ENV['FRONTEND_URL']等へ書き込んでいる場合、putenv()だけでは
        // 上書きできない。そのため3つの表現すべてを直接書き換える。
        foreach (self::ENV_KEYS as $key) {
            $this->originalGetenv[$key] = getenv($key);
            $this->originalEnvSuper[$key] = $_ENV[$key] ?? null;
            $this->originalServerSuper[$key] = $_SERVER[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $original = $this->originalGetenv[$key];
            if ($original === false) {
                putenv($key);
            } else {
                putenv("{$key}={$original}");
            }

            if ($this->originalEnvSuper[$key] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $this->originalEnvSuper[$key];
            }

            if ($this->originalServerSuper[$key] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $this->originalServerSuper[$key];
            }
        }

        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public function test_cors_config_does_not_throw_when_frontend_url_is_empty_in_production(): void
    {
        $this->setEnv('APP_ENV', 'production');
        $this->setEnv('FRONTEND_URL', '');
        $this->setEnv('CORS_ALLOWED_ORIGINS', '');

        $config = require __DIR__.'/../../../config/cors.php';

        $this->assertIsArray($config);
        $this->assertSame([], $config['allowed_origins']);
        $this->assertTrue($config['supports_credentials']);
    }

    public function test_cors_config_does_not_throw_when_wildcard_is_supplied(): void
    {
        $this->setEnv('APP_ENV', 'production');
        $this->setEnv('FRONTEND_URL', '*');
        $this->setEnv('CORS_ALLOWED_ORIGINS', '*');

        $config = require __DIR__.'/../../../config/cors.php';

        $this->assertIsArray($config);
        $this->assertNotContains('*', $config['allowed_origins']);
    }

    public function test_session_config_does_not_throw_when_same_site_none_without_secure(): void
    {
        $this->setEnv('SESSION_SAME_SITE', 'none');
        $this->setEnv('SESSION_SECURE_COOKIE', '');

        $config = require __DIR__.'/../../../config/session.php';

        $this->assertIsArray($config);
        $this->assertSame('none', $config['same_site']);
    }
}
