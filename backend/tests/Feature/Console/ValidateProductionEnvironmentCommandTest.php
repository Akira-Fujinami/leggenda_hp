<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ValidateProductionEnvironmentCommandTest extends TestCase
{
    public function test_command_exits_zero_when_configuration_is_valid(): void
    {
        $this->configureValidProduction();

        $exitCode = Artisan::call('app:validate-production-env');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('valid', Artisan::output());
    }

    public function test_command_exits_one_when_frontend_url_is_missing(): void
    {
        Config::set('app.env', 'production');
        Config::set('cors.allowed_origins', []);
        Config::set('cors.frontend_url', null);

        $exitCode = Artisan::call('app:validate-production-env');

        $this->assertSame(1, $exitCode);
    }

    public function test_command_never_outputs_secret_looking_values(): void
    {
        Config::set('app.env', 'production');
        Config::set('cors.allowed_origins', []);
        Config::set('cors.frontend_url', null);
        Config::set('app.key', 'base64:some-fake-app-key-value-not-a-real-secret==');

        Artisan::call('app:validate-production-env');
        $output = Artisan::output();

        $this->assertStringNotContainsString('APP_KEY', $output);
        $this->assertStringNotContainsString('some-fake-app-key-value-not-a-real-secret', $output);
    }

    private function configureValidProduction(): void
    {
        Config::set('app.env', 'production');
        Config::set('app.url', 'https://api.example.com');
        Config::set('cors.allowed_origins', ['https://app.example.com']);
        Config::set('cors.frontend_url', 'https://app.example.com');
        Config::set('sanctum.stateful', ['app.example.com']);
        Config::set('session.same_site', 'none');
        Config::set('session.secure', true);
        Config::set('session.domain', null);
    }
}
