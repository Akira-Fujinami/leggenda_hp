<?php

namespace App\Services\TopMessagePanel;

use App\Services\ComparisonCommentary\ComparisonCommentaryException;

/**
 * 依頼CZ: AIの呼び出し先は既存の設定(BRAND_WHEEL_AI_PROVIDER)に従う。openai以外(mock等)では選ばせない
 * ―― モックが作った作り話を営業資料に載せないため。
 */
class TopMessagePanelProviderFactory
{
    public function make(): TopMessagePanelProvider
    {
        $provider = (string) config('services.brand_wheel_ai.provider', 'mock');

        if ($provider !== 'openai') {
            throw new ComparisonCommentaryException('PROVIDER_NOT_OPENAI', "BRAND_WHEEL_AI_PROVIDER={$provider} のため、パネルは作れません(openaiのみ対応)。");
        }

        if ((string) config('services.openai.api_key') === '') {
            throw new ComparisonCommentaryException('OPENAI_NOT_CONFIGURED', 'OPENAI_API_KEYが設定されていません。');
        }

        return new OpenAiTopMessagePanelProvider;
    }
}
