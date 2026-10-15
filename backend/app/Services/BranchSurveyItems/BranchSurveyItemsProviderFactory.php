<?php

namespace App\Services\BranchSurveyItems;

/**
 * 依頼CT: AIの呼び出し先は既存の設定(BRAND_WHEEL_AI_PROVIDER)に従う。openai以外(mock等)では判定しない
 * ―― モックが作った作り話を営業資料に載せないため。
 */
class BranchSurveyItemsProviderFactory
{
    public function make(): BranchSurveyItemsProvider
    {
        $provider = (string) config('services.brand_wheel_ai.provider', 'mock');

        if ($provider !== 'openai') {
            throw new BranchSurveyItemsException('PROVIDER_NOT_OPENAI', "BRAND_WHEEL_AI_PROVIDER={$provider} のため、枝ごとの判定は行えません(openaiのみ対応)。");
        }

        if ((string) config('services.openai.api_key') === '') {
            throw new BranchSurveyItemsException('OPENAI_NOT_CONFIGURED', 'OPENAI_API_KEYが設定されていません。');
        }

        return new OpenAiBranchSurveyItemsProvider;
    }
}
