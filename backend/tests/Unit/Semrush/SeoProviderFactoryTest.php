<?php

namespace Tests\Unit\Semrush;

use App\Services\Semrush\MockSeoDataProvider;
use App\Services\Semrush\SemrushSeoDataProvider;
use App\Services\Semrush\SeoProviderException;
use App\Services\Semrush\SeoProviderFactory;
use Tests\TestCase;

class SeoProviderFactoryTest extends TestCase
{
    public function test_returns_mock_provider_when_configured_not_production_and_explicitly_allowed(): void
    {
        config(['analysis.seo_provider' => 'mock', 'analysis.allow_mock_providers' => true]);
        app()->detectEnvironment(fn () => 'testing');

        $provider = (new SeoProviderFactory)->make();

        $this->assertInstanceOf(MockSeoDataProvider::class, $provider);
    }

    public function test_rejects_mock_outside_production_when_not_explicitly_allowed(): void
    {
        config(['analysis.seo_provider' => 'mock', 'analysis.allow_mock_providers' => false]);
        app()->detectEnvironment(fn () => 'testing');

        try {
            (new SeoProviderFactory)->make();
            $this->fail('SeoProviderException was not thrown.');
        } catch (SeoProviderException $e) {
            $this->assertSame('MOCK_PROVIDER_NOT_ALLOWED', $e->errorCode);
        }
    }

    public function test_refuses_mock_provider_in_production_environment_even_if_allowed(): void
    {
        config(['analysis.seo_provider' => 'mock', 'analysis.allow_mock_providers' => true]);
        app()->detectEnvironment(fn () => 'production');

        try {
            (new SeoProviderFactory)->make();
            $this->fail('SeoProviderException was not thrown for mock-in-production.');
        } catch (SeoProviderException $e) {
            $this->assertSame('MOCK_PROVIDER_NOT_ALLOWED_IN_PRODUCTION', $e->errorCode);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_returns_semrush_provider_when_api_key_configured(): void
    {
        config(['analysis.seo_provider' => 'semrush', 'services.semrush.api_key' => 'test-key']);

        $provider = (new SeoProviderFactory)->make();

        $this->assertInstanceOf(SemrushSeoDataProvider::class, $provider);
    }

    public function test_semrush_without_api_key_does_not_silently_fall_back_to_mock(): void
    {
        config(['analysis.seo_provider' => 'semrush', 'services.semrush.api_key' => '']);

        try {
            (new SeoProviderFactory)->make();
            $this->fail('SeoProviderException was not thrown.');
        } catch (SeoProviderException $e) {
            $this->assertSame('SEMRUSH_NOT_CONFIGURED', $e->errorCode);
        }
    }
}
