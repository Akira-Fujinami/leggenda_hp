<?php

namespace App\Support;

class CorsOrigins
{
    /**
     * FRONTEND_URL(必須の主要Origin)とCORS_ALLOWED_ORIGINS(カンマ区切りの追加許可)から
     * 空文字・末尾スラッシュ・重複・"*" を取り除いた正規化済みCORS許可Origin一覧を作る。
     * supports_credentials=true と "*" は仕様上併用できないため、"*" は常に除外する。
     *
     * Collectionではなくプレーンな配列関数のみを使う(config/cors.php から直接呼ばれるため)。
     */
    public static function resolve(?string $frontendUrl, ?string $extraOrigins): array
    {
        $candidates = array_merge(
            [$frontendUrl],
            explode(',', (string) $extraOrigins),
        );

        $origins = [];

        foreach ($candidates as $candidate) {
            $origin = rtrim(trim((string) $candidate), '/');

            if ($origin === '' || $origin === '*') {
                continue;
            }

            if (! in_array($origin, $origins, true)) {
                $origins[] = $origin;
            }
        }

        return $origins;
    }

    /**
     * production相当の環境でCORS許可Originが空のまま起動しないようにする。
     *
     * 【重要】config/cors.php からは呼ばない。config読み込みは
     * `composer dump-autoload`(→`artisan package:discover`)経由でDockerビルド時にも
     * 実行され、その時点ではFRONTEND_URL等のRuntime Environment Variablesが
     * まだ存在しないため、ここで例外を投げるとDockerビルド自体が失敗してしまう
     * (実際にこの不具合が発生した)。実行時(コンテナ起動時)の検証は
     * App\Support\ProductionEnvironmentValidator (php artisan app:validate-production-env)
     * がconfig()の値を見て呼び出す。
     *
     * local/testingのみ、未設定時にlocalhostへ暗黙フォールバックすることを許容する
     * (呼び出し側のconfig/cors.phpでフォールバックを行う)。それ以外の環境
     * (production・ステージング等の明示的な環境名)ではFRONTEND_URLの設定を必須とする。
     */
    public static function assertConfigured(array $origins, string $environment): void
    {
        if ($origins !== [] || in_array($environment, ['local', 'testing'], true)) {
            return;
        }

        throw new \RuntimeException(
            "CORS allowed_origins is empty for APP_ENV={$environment}. ".
            'Set FRONTEND_URL (and optionally CORS_ALLOWED_ORIGINS) before starting the app.'
        );
    }
}
