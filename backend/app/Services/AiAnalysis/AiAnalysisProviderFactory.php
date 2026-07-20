<?php

namespace App\Services\AiAnalysis;

/**
 * AI_PROVIDER設定からAiAnalysisProvider実装を解決する。
 * SeoProviderFactoryと同じ方針: 未対応のProvider名が指定された場合や、
 * production環境でmockが指定された場合は、黙ってmockへフォールバック
 * したり本物として扱ったりせず、明確な設定エラーとして例外を投げる。
 */
class AiAnalysisProviderFactory
{
    /**
     * 将来実装予定だが、現時点では未実装のProvider名。
     * 完全に不明な値と区別してエラーメッセージを分かりやすくするために保持する。
     *
     * @var list<string>
     */
    private const PLANNED_BUT_NOT_IMPLEMENTED = ['openai', 'anthropic'];

    public function make(): AiAnalysisProvider
    {
        $provider = (string) config('services.ai.provider', 'mock');

        if ($provider === 'mock') {
            return $this->makeMock();
        }

        if (in_array($provider, self::PLANNED_BUT_NOT_IMPLEMENTED, true)) {
            throw new AiAnalysisException(
                'AI_PROVIDER_NOT_IMPLEMENTED',
                "AI_PROVIDERに\"{$provider}\"が指定されていますが、このProviderはまだ実装されていません。現時点ではmockのみサポートしています。",
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

        return new MockAiAnalysisProvider;
    }
}
