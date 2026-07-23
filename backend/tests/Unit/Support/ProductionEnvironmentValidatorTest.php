<?php

namespace Tests\Unit\Support;

use App\Support\ProductionEnvironmentValidator;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ProductionEnvironmentValidatorTest extends TestCase
{
    public function test_passes_for_local_environment_regardless_of_config(): void
    {
        Config::set('app.env', 'local');
        Config::set('cors.allowed_origins', []);
        Config::set('cors.frontend_url', null);

        $this->assertSame([], ProductionEnvironmentValidator::validate());
    }

    public function test_passes_for_testing_environment_regardless_of_config(): void
    {
        Config::set('app.env', 'testing');
        Config::set('cors.allowed_origins', []);
        Config::set('cors.frontend_url', null);

        $this->assertSame([], ProductionEnvironmentValidator::validate());
    }

    public function test_passes_in_production_with_a_fully_valid_configuration(): void
    {
        $this->configureValidProduction();

        $this->assertSame([], ProductionEnvironmentValidator::validate());
    }

    public function test_fails_in_production_when_frontend_url_and_allowed_origins_are_empty(): void
    {
        $this->configureValidProduction();
        Config::set('cors.allowed_origins', []);
        Config::set('cors.frontend_url', null);

        $errors = ProductionEnvironmentValidator::validate();

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('allowed_origins is empty', implode(' ', $errors));
    }

    public function test_fails_when_cors_allowed_origins_contains_wildcard(): void
    {
        $this->configureValidProduction();
        Config::set('cors.allowed_origins', ['*']);

        $errors = ProductionEnvironmentValidator::validate();

        $this->assertNotEmpty($errors);
    }

    public function test_fails_when_frontend_url_is_not_a_valid_http_origin(): void
    {
        $this->configureValidProduction();
        Config::set('cors.frontend_url', 'not-a-url');

        $errors = ProductionEnvironmentValidator::validate();

        $this->assertNotEmpty($errors);
    }

    public function test_fails_when_same_site_none_without_secure(): void
    {
        $this->configureValidProduction();
        Config::set('session.secure', false);

        $errors = ProductionEnvironmentValidator::validate();

        $this->assertNotEmpty($errors);
    }

    public function test_passes_when_same_site_none_with_secure_true(): void
    {
        $this->configureValidProduction();
        Config::set('session.same_site', 'none');
        Config::set('session.secure', true);

        $this->assertSame([], ProductionEnvironmentValidator::validate());
    }

    public function test_fails_when_same_site_none_and_session_domain_is_set(): void
    {
        $this->configureValidProduction();
        Config::set('session.same_site', 'none');
        Config::set('session.secure', true);
        Config::set('session.domain', '.example.com');

        $errors = ProductionEnvironmentValidator::validate();

        $this->assertNotEmpty($errors);
    }

    public function test_passes_for_custom_domain_pattern_with_same_site_lax_and_shared_domain(): void
    {
        $this->configureValidProduction();
        Config::set('session.same_site', 'lax');
        Config::set('session.secure', true);
        Config::set('session.domain', '.example.com');

        $this->assertSame([], ProductionEnvironmentValidator::validate());
    }

    public function test_fails_when_sanctum_stateful_domains_is_empty(): void
    {
        $this->configureValidProduction();
        Config::set('sanctum.stateful', []);

        $errors = ProductionEnvironmentValidator::validate();

        $this->assertNotEmpty($errors);
    }

    public function test_fails_when_sanctum_stateful_domains_contains_a_scheme(): void
    {
        $this->configureValidProduction();
        Config::set('sanctum.stateful', ['https://app.example.com']);

        $errors = ProductionEnvironmentValidator::validate();

        $this->assertNotEmpty($errors);
    }

    public function test_fails_when_app_url_is_not_https(): void
    {
        $this->configureValidProduction();
        Config::set('app.url', 'http://api.example.com');

        $errors = ProductionEnvironmentValidator::validate();

        $this->assertNotEmpty($errors);
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
