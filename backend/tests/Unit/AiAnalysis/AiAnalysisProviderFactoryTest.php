<?php

namespace Tests\Unit\AiAnalysis;

use App\Services\AiAnalysis\AiAnalysisException;
use App\Services\AiAnalysis\AiAnalysisProviderFactory;
use App\Services\AiAnalysis\MockAiAnalysisProvider;
use Tests\TestCase;

class AiAnalysisProviderFactoryTest extends TestCase
{
    public function test_returns_mock_provider_when_configured_and_not_production(): void
    {
        config(['services.ai.provider' => 'mock']);
        app()->detectEnvironment(fn () => 'testing');

        $provider = (new AiAnalysisProviderFactory)->make();

        $this->assertInstanceOf(MockAiAnalysisProvider::class, $provider);
    }

    public function test_throws_a_clear_error_for_a_planned_but_unimplemented_provider(): void
    {
        config(['services.ai.provider' => 'openai']);

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
            $provider = (new AiAnalysisProviderFactory)->make();
        } catch (AiAnalysisException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected an AiAnalysisException instead of a silent mock fallback.');
        $this->assertNotInstanceOf(MockAiAnalysisProvider::class, $thrown);
    }

    public function test_refuses_mock_provider_in_production_environment(): void
    {
        config(['services.ai.provider' => 'mock']);
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
