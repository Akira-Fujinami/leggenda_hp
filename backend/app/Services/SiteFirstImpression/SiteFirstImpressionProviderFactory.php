<?php

namespace App\Services\SiteFirstImpression;

use App\Services\ComparisonCommentary\ComparisonCommentaryException;

/**
 * 依頼CX: AIの呼び出し先は既存の設定(BRAND_WHEEL_AI_PROVIDER)に従う。openai以外(mock等)では書かない
 * ―― モックが作った作り話を営業資料に載せないため。画像を読めるモデルであることは、設定した側の責任
 * (OpenAiSiteFirstImpressionProviderは画像をimage_urlで渡す。読めないモデルのときはAPIがエラーを返し、結果のファイルに記録される)。
 */
class SiteFirstImpressionProviderFactory
{
    public function make(): SiteFirstImpressionProvider
    {
        $provider = (string) config('services.brand_wheel_ai.provider', 'mock');

        if ($provider !== 'openai') {
            throw new ComparisonCommentaryException('PROVIDER_NOT_OPENAI', "BRAND_WHEEL_AI_PROVIDER={$provider} のため、第一印象は書けません(openaiのみ対応)。");
        }

        if ((string) config('services.openai.api_key') === '') {
            throw new ComparisonCommentaryException('OPENAI_NOT_CONFIGURED', 'OPENAI_API_KEYが設定されていません。');
        }

        return new OpenAiSiteFirstImpressionProvider;
    }
}
