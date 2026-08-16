<?php

namespace App\Services\BrandWheel;

use App\Support\MockProviderGuard;

/**
 * services.brand_wheel_ai.provider設定からBrandWheelImprovementSuggestionProvider
 * 実装を解決する。BrandWheelAnalysisProviderFactoryと同じ設定・同じmock
 * production拒否判定(MockProviderGuard)を共有する ―― 同じ「ブランド・ホイール」
 * サブシステムの一部であり、改善提案だけ別のprovider設定を持たせる理由が
 * 無いため。
 */
class BrandWheelImprovementSuggestionProviderFactory
{
    public function make(): BrandWheelImprovementSuggestionProvider
    {
        $provider = (string) config('services.brand_wheel_ai.provider', 'mock');

        return match ($provider) {
            'mock' => $this->makeMock(),
            'openai' => $this->makeOpenAi(),
            default => $this->makeUnknown($provider),
        };
    }

    private function makeUnknown(string $provider): never
    {
        throw new BrandWheelAnalysisException(
            'BRAND_WHEEL_PROVIDER_INVALID',
            "BRAND_WHEEL_AI_PROVIDERに不明な値が設定されています: {$provider}",
        );
    }

    private function makeMock(): BrandWheelImprovementSuggestionProvider
    {
        $rejection = MockProviderGuard::rejectionReason();

        if ($rejection === MockProviderGuard::REASON_PRODUCTION) {
            throw new BrandWheelAnalysisException(
                'BRAND_WHEEL_PROVIDER_MOCK_IN_PRODUCTION',
                'production環境ではBRAND_WHEEL_AI_PROVIDER=mockを使用できません。意図せずモックデータが'.
                '本物の分析結果として表示されるのを防ぐため、実際のAI Providerを設定してください。',
            );
        }

        if ($rejection === MockProviderGuard::REASON_NOT_EXPLICITLY_ALLOWED) {
            throw new BrandWheelAnalysisException(
                'BRAND_WHEEL_MOCK_PROVIDER_NOT_ALLOWED',
                'BRAND_WHEEL_AI_PROVIDER=mockを使用するにはALLOW_MOCK_PROVIDERS=trueの設定が必要です。',
            );
        }

        return new MockBrandWheelImprovementSuggestionProvider;
    }

    private function makeOpenAi(): BrandWheelImprovementSuggestionProvider
    {
        $apiKey = (string) config('services.openai.api_key');

        if ($apiKey === '') {
            throw new BrandWheelAnalysisException(
                'OPENAI_NOT_CONFIGURED',
                'BRAND_WHEEL_AI_PROVIDER=openaiが指定されていますが、OPENAI_API_KEYが設定されていません。',
            );
        }

        return new OpenAiBrandWheelImprovementSuggestionProvider(new BrandWheelImprovementSuggestionResponseParser);
    }
}
