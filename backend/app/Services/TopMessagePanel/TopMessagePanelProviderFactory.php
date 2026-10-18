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
        // 依頼DD-3: パネルのAIの呼び出し先。anthropic(Claude)のときは、キーとモデル名が無ければAIを呼ばない(呼び出し側は機械の選択になる)。
        if ((string) config('top_message_panel.provider', 'openai') === 'anthropic') {
            if ((string) config('services.anthropic.api_key') === '') {
                throw new ComparisonCommentaryException('ANTHROPIC_NOT_CONFIGURED', 'ANTHROPIC_API_KEYが設定されていません。');
            }
            if ((string) config('top_message_panel.model') === '') {
                throw new ComparisonCommentaryException('MODEL_NOT_CONFIGURED', 'TOP_MESSAGE_PANEL_MODELが設定されていません。');
            }

            return new AnthropicTopMessagePanelProvider;
        }

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
