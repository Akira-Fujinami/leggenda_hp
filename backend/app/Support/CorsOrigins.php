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
     * config/cors.php から呼ばれるため、`php artisan config:cache` 実行時にも
     * 検知できる(=デプロイのconfig:cacheステップ自体が失敗し、CORSが事実上
     * 無効化されたまま本番稼働してしまう事態を防ぐ)。
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
