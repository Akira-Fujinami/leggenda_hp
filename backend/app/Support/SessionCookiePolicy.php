<?php

namespace App\Support;

class SessionCookiePolicy
{
    /**
     * SESSION_SAME_SITE=none はブラウザ仕様上 Secure Cookie が必須
     * (Secureの付かない SameSite=None は多くのブラウザがCookieごと拒否する)。
     * frontend/backendを別ドメイン(別Origin)で運用する構成で踏みがちな設定ミスのため、
     * config/session.php から呼び出し、`php artisan config:cache` 実行時にも検知できるようにする。
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
