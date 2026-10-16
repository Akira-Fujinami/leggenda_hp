<?php

namespace App\Services\StrengthWeaknessInsight;

use App\Services\ComparisonCommentary\ComparisonCommentaryException;

/**
 * 依頼DB-1: Claude(Anthropic)の呼び出し口を作る。APIキー・モデル名が env に無いときは、例外(ComparisonCommentaryException)を投げる
 * ―― 呼び出し側(StrengthWeaknessInsightService)が受けて、AIを呼ばず依頼CT-1の文にする(比較は失敗させない)。
 */
class StrengthWeaknessInsightProviderFactory
{
    public function make(): StrengthWeaknessInsightProvider
    {
        if ((string) config('services.anthropic.api_key') === '') {
            throw new ComparisonCommentaryException('ANTHROPIC_NOT_CONFIGURED', 'ANTHROPIC_API_KEYが設定されていません。');
        }
        if ((string) config('strength_weakness_insight.model') === '') {
            throw new ComparisonCommentaryException('MODEL_NOT_CONFIGURED', 'STRENGTH_WEAKNESS_INSIGHT_MODELが設定されていません。');
        }

        return new AnthropicStrengthWeaknessInsightProvider;
    }
}
