<?php

namespace App\Support;

/**
 * production環境でのmock Provider使用を拒否する共通ガード。
 * AiAnalysisProviderFactoryとBrandWheelAnalysisProviderFactoryの両方が
 * 同じ二段階チェック(1. productionでは常に拒否 2. それ以外でも
 * ALLOW_MOCK_PROVIDERS=trueの明示が無ければ拒否)を必要とするため、
 * 判定ロジック自体をここに集約し、重複させない。
 *
 * 実際に投げる例外の型・errorCode・メッセージはProvider固有のため、
 * ここでは「拒否理由」だけを返し、呼び出し側(各Factory)が自分の
 * 例外型で投げる。
 */
class MockProviderGuard
{
    public const string REASON_PRODUCTION = 'production';

    public const string REASON_NOT_EXPLICITLY_ALLOWED = 'not_explicitly_allowed';

    /**
     * @return string|null 拒否理由(self::REASON_*)。nullなら許可。
     */
    public static function rejectionReason(): ?string
    {
        if (app()->environment('production')) {
            return self::REASON_PRODUCTION;
        }

        if (! (bool) config('analysis.allow_mock_providers')) {
            return self::REASON_NOT_EXPLICITLY_ALLOWED;
        }

        return null;
    }
}
