<?php

namespace App\Support;

class ProductionEnvironmentValidator
{
    private const NON_ENFORCED_ENVIRONMENTS = ['local', 'testing'];

    /**
     * production相当の環境で、CORS/Sanctum/Session Cookie周りの設定が
     * 正しく揃っているかを実行時(コンテナ起動時)に検証する。
     *
     * config()の値のみを見る(env()は使わない)。config:cache実行後は
     * .envが再読込されず env() が信頼できなくなるため。
     *
     * @return string[] 検知したエラーメッセージの一覧(空配列なら検証OK)。
     *                   メッセージには変数名と何が問題かのみを含め、実際の値
     *                   (Secretになり得るものも含む)は一切出力しない。
     */
    public static function validate(): array
    {
        $environment = (string) config('app.env', 'production');

        if (in_array($environment, self::NON_ENFORCED_ENVIRONMENTS, true)) {
            return [];
        }

        $errors = [];

        $allowedOrigins = (array) config('cors.allowed_origins', []);
        $frontendUrl = config('cors.frontend_url');

        try {
            CorsOrigins::assertConfigured($allowedOrigins, $environment);
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        if (! blank($frontendUrl) && ! self::isValidHttpOrigin((string) $frontendUrl)) {
            $errors[] = 'FRONTEND_URL must be a valid http(s) URL (e.g. https://app.example.com).';
        }

        if (in_array('*', $allowedOrigins, true)) {
            $errors[] = 'CORS_ALLOWED_ORIGINS/FRONTEND_URL must not resolve to "*" while supports_credentials is true.';
        }

        $sameSite = config('session.same_site');
        $secureCookie = config('session.secure');

        try {
            SessionCookiePolicy::assertSecureWhenSameSiteIsNone($sameSite, $secureCookie);
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        $sessionDomain = config('session.domain');

        if ($sameSite === 'none' && ! blank($sessionDomain)) {
            $errors[] = 'SESSION_DOMAIN should be empty when SESSION_SAME_SITE=none '.
                '(cross-site cookies cannot be scoped to a shared parent domain).';
        }

        $statefulDomains = array_values(array_filter(
            (array) config('sanctum.stateful', []),
            fn ($domain) => ! blank($domain)
        ));

        if ($statefulDomains === []) {
            $errors[] = "SANCTUM_STATEFUL_DOMAINS must not be empty when APP_ENV={$environment}.";
        }

        foreach ($statefulDomains as $domain) {
            if (is_string($domain) && preg_match('#^https?://#i', $domain) === 1) {
                $errors[] = 'SANCTUM_STATEFUL_DOMAINS must not include a scheme (use host[:port] only).';
                break;
            }
        }

        $appUrl = (string) config('app.url', '');

        if (! self::isValidHttpsUrl($appUrl)) {
            $errors[] = 'APP_URL must be a valid https:// URL.';
        }

        return $errors;
    }

    private static function isValidHttpOrigin(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true) && ! blank($host);
    }

    private static function isValidHttpsUrl(string $url): bool
    {
        return parse_url($url, PHP_URL_SCHEME) === 'https' && ! blank(parse_url($url, PHP_URL_HOST));
    }
}
