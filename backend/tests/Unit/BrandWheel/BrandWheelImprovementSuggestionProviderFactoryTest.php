<?php

namespace Tests\Unit\BrandWheel;

use App\Services\BrandWheel\BrandWheelAnalysisException;
use App\Services\BrandWheel\BrandWheelImprovementSuggestionProviderFactory;
use App\Services\BrandWheel\MockBrandWheelImprovementSuggestionProvider;
use App\Services\BrandWheel\OpenAiBrandWheelImprovementSuggestionProvider;
use Tests\TestCase;

class BrandWheelImprovementSuggestionProviderFactoryTest extends TestCase
{
    public function test_returns_mock_provider_when_configured_not_production_and_explicitly_allowed(): void
    {
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);
        app()->detectEnvironment(fn () => 'testing');

        $provider = (new BrandWheelImprovementSuggestionProviderFactory)->make();

        $this->assertInstanceOf(MockBrandWheelImprovementSuggestionProvider::class, $provider);
    }

    public function test_refuses_mock_provider_in_production_environment(): void
    {
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);
        app()->detectEnvironment(fn () => 'production');

        try {
            (new BrandWheelImprovementSuggestionProviderFactory)->make();
            $this->fail('BrandWheelAnalysisException was not thrown for mock-in-production.');
        } catch (BrandWheelAnalysisException $e) {
            $this->assertSame('BRAND_WHEEL_PROVIDER_MOCK_IN_PRODUCTION', $e->errorCode);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_returns_openai_provider_when_api_key_configured(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);

        $provider = (new BrandWheelImprovementSuggestionProviderFactory)->make();

        $this->assertInstanceOf(OpenAiBrandWheelImprovementSuggestionProvider::class, $provider);
    }

    public function test_openai_without_an_api_key_throws_a_configuration_error(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => '']);

        try {
            (new BrandWheelImprovementSuggestionProviderFactory)->make();
            $this->fail('BrandWheelAnalysisException was not thrown.');
        } catch (BrandWheelAnalysisException $e) {
            $this->assertSame('OPENAI_NOT_CONFIGURED', $e->errorCode);
        }
    }

    public function test_throws_a_clear_error_for_a_completely_unknown_provider(): void
    {
        config(['services.brand_wheel_ai.provider' => 'totally-unknown-provider']);

        try {
            (new BrandWheelImprovementSuggestionProviderFactory)->make();
            $this->fail('BrandWheelAnalysisException was not thrown.');
        } catch (BrandWheelAnalysisException $e) {
            $this->assertSame('BRAND_WHEEL_PROVIDER_INVALID', $e->errorCode);
        }
    }
}
