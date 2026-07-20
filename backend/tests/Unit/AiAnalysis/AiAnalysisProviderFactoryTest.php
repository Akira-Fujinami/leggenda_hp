<?php

namespace Tests\Unit\AiAnalysis;

use App\Services\AiAnalysis\AiAnalysisException;
use App\Services\AiAnalysis\AiAnalysisProviderFactory;
use App\Services\AiAnalysis\MockAiAnalysisProvider;
use App\Services\AiAnalysis\OpenAiAnalysisProvider;
use Tests\TestCase;

class AiAnalysisProviderFactoryTest extends TestCase
{
    public function test_returns_mock_provider_when_configured_not_production_and_explicitly_allowed(): void
    {
        config(['services.ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);
        app()->detectEnvironment(fn () => 'testing');

        $provider = (new AiAnalysisProviderFactory)->make();

        $this->assertInstanceOf(MockAiAnalysisProvider::class, $provider);
    }

    public function test_rejects_mock_outside_production_when_not_explicitly_allowed(): void
    {
        config(['services.ai.provider' => 'mock', 'analysis.allow_mock_providers' => false]);
        app()->detectEnvironment(fn () => 'testing');

        try {
            (new AiAnalysisProviderFactory)->make();
            $this->fail('AiAnalysisException was not thrown.');
        } catch (AiAnalysisException $e) {
            $this->assertSame('MOCK_PROVIDER_NOT_ALLOWED', $e->errorCode);
        }
    }

    public function test_returns_openai_provider_when_api_key_configured(): void
    {
        config(['services.ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);

        $provider = (new AiAnalysisProviderFactory)->make();

        $this->assertInstanceOf(OpenAiAnalysisProvider::class, $provider);
    }

    public function test_openai_without_an_api_key_throws_a_configuration_error(): void
    {
        config(['services.ai.provider' => 'openai', 'services.openai.api_key' => '']);

        try {
            (new AiAnalysisProviderFactory)->make();
            $this->fail('AiAnalysisException was not thrown.');
        } catch (AiAnalysisException $e) {
            $this->assertSame('OPENAI_NOT_CONFIGURED', $e->errorCode);
        }
    }

    public function test_throws_a_clear_error_for_a_planned_but_unimplemented_provider(): void
    {
        config(['services.ai.provider' => 'anthropic']);

        try {
            (new AiAnalysisProviderFactory)->make();
            $this->fail('AiAnalysisException was not thrown.');
        } catch (AiAnalysisException $e) {
            $this->assertSame('AI_PROVIDER_NOT_IMPLEMENTED', $e->errorCode);
        }
    }

    public function test_throws_a_clear_error_for_a_completely_unknown_provider(): void
    {
        config(['services.ai.provider' => 'totally-unknown-provider']);

        try {
            (new AiAnalysisProviderFactory)->make();
            $this->fail('AiAnalysisException was not thrown.');
        } catch (AiAnalysisException $e) {
            $this->assertSame('AI_PROVIDER_INVALID', $e->errorCode);
        }
    }

    public function test_never_silently_falls_back_to_mock_for_an_invalid_provider(): void
    {
        config(['services.ai.provider' => 'anthropic']);

        $thrown = null;
        try {
            (new AiAnalysisProviderFactory)->make();
        } catch (AiAnalysisException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected an AiAnalysisException instead of a silent mock fallback.');
    }

    public function test_refuses_mock_provider_in_production_environment(): void
    {
        config(['services.ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);
        app()->detectEnvironment(fn () => 'production');

        try {
            (new AiAnalysisProviderFactory)->make();
            $this->fail('AiAnalysisException was not thrown for mock-in-production.');
        } catch (AiAnalysisException $e) {
            $this->assertSame('AI_PROVIDER_MOCK_IN_PRODUCTION', $e->errorCode);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }
}
