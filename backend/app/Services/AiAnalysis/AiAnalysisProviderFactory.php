<?php

namespace App\Services\AiAnalysis;

/**
 * AI_PROVIDER設定からAiAnalysisProvider実装を解決する。
 * SeoProviderFactoryと同じ方針: 未対応のProvider名が指定された場合や、
 * production環境でmockが指定された場合は、黙ってmockへフォールバック
 * したり本物として扱ったりせず、明確な設定エラーとして例外を投げる。
 *
 * mockの利用は二重にガードする:
 * 1. production環境では常に拒否(ALLOW_MOCK_PROVIDERSの値に関わらず)。
 * 2. production以外でも、ALLOW_MOCK_PROVIDERS=trueを明示しない限り拒否する。
 */
class AiAnalysisProviderFactory
{
    /**
     * 将来実装予定だが、現時点では未実装のProvider名。
     * 完全に不明な値と区別してエラーメッセージを分かりやすくするために保持する。
     *
     * @var list<string>
     */
    private const PLANNED_BUT_NOT_IMPLEMENTED = ['anthropic'];

    public function make(): AiAnalysisProvider
    {
        $provider = (string) config('services.ai.provider', 'mock');

        return match ($provider) {
            'mock' => $this->makeMock(),
            'openai' => $this->makeOpenAi(),
            default => $this->makeUnknown($provider),
        };
    }

    private function makeUnknown(string $provider): never
    {
        if (in_array($provider, self::PLANNED_BUT_NOT_IMPLEMENTED, true)) {
            throw new AiAnalysisException(
                'AI_PROVIDER_NOT_IMPLEMENTED',
                "AI_PROVIDERに\"{$provider}\"が指定されていますが、このProviderはまだ実装されていません。現時点ではmock/openaiのみサポートしています。",
            );
        }

        throw new AiAnalysisException(
            'AI_PROVIDER_INVALID',
            "AI_PROVIDERに不明な値が設定されています: {$provider}",
        );
    }

    private function makeMock(): AiAnalysisProvider
    {
        if (app()->environment('production')) {
            throw new AiAnalysisException(
                'AI_PROVIDER_MOCK_IN_PRODUCTION',
                'production環境ではAI_PROVIDER=mockを使用できません。意図せずモックデータが本物の分析結果として表示されるのを防ぐため、'.
                '実際のAI Providerを設定してください。',
            );
        }

        if (! (bool) config('analysis.allow_mock_providers')) {
            throw new AiAnalysisException(
                'MOCK_PROVIDER_NOT_ALLOWED',
                'AI_PROVIDER=mockを使用するにはALLOW_MOCK_PROVIDERS=trueの設定が必要です。',
            );
        }

        return new MockAiAnalysisProvider;
    }

    private function makeOpenAi(): AiAnalysisProvider
    {
        $apiKey = (string) config('services.openai.api_key');

        if ($apiKey === '') {
            throw new AiAnalysisException(
                'OPENAI_NOT_CONFIGURED',
                'AI_PROVIDER=openaiが指定されていますが、OPENAI_API_KEYが設定されていません。',
            );
        }

        return new OpenAiAnalysisProvider(new AiAnalysisResponseParser);
    }
}
