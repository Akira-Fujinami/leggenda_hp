<?php

namespace App\Support;

class SessionCookiePolicy
{
    /**
     * SESSION_SAME_SITE=none はブラウザ仕様上 Secure Cookie が必須
     * (Secureの付かない SameSite=None は多くのブラウザがCookieごと拒否する)。
     * frontend/backendを別ドメイン(別Origin)で運用する構成で踏みがちな設定ミスのため検証する。
     *
     * 【重要】config/session.php からは呼ばない(Dockerビルド時のconfig読み込みで
     * 例外を投げるとビルド自体が失敗するため)。実行時(コンテナ起動時)の検証は
     * App\Support\ProductionEnvironmentValidator (php artisan app:validate-production-env)
     * がconfig('session.same_site')/config('session.secure')を見て呼び出す。
     */
    public static function assertSecureWhenSameSiteIsNone(?string $sameSite, mixed $secureCookie): void
    {
        if ($sameSite !== 'none') {
            return;
        }

        if (filter_var($secureCookie, FILTER_VALIDATE_BOOL) === true) {
            return;
        }

        throw new \RuntimeException(
            'SESSION_SAME_SITE=none requires SESSION_SECURE_COOKIE=true '.
            '(browsers reject SameSite=None cookies that are not marked Secure).'
        );
    }
}
