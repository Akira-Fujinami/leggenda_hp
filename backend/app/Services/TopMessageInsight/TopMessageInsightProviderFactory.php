<?php

namespace App\Services\TopMessageInsight;

/**
 * 依頼CQ-3: AIの呼び出し先は既存の設定(BRAND_WHEEL_AI_PROVIDER)に従う。
 * openai以外(mock等)では、このページは作らない ―― モックが作った作り話を
 * 営業資料に載せないため。
 */
class TopMessageInsightProviderFactory
{
    public function make(): TopMessageInsightProvider
    {
        $provider = (string) config('services.brand_wheel_ai.provider', 'mock');

        if ($provider !== 'openai') {
            throw new TopMessageInsightException(
                'PROVIDER_NOT_OPENAI',
                "BRAND_WHEEL_AI_PROVIDER={$provider} のため、トップメッセージのページは作れません(openaiのみ対応)。",
            );
        }

        if ((string) config('services.openai.api_key') === '') {
            throw new TopMessageInsightException('OPENAI_NOT_CONFIGURED', 'OPENAI_API_KEYが設定されていません。');
        }

        return new OpenAiTopMessageInsightProvider;
    }
}
