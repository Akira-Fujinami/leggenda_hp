<?php

use App\Support\CorsOrigins;

// frontendとbackendを別Origin(別ドメイン)のRender Web Serviceとして運用するため、
// 許可Originは FRONTEND_URL(必須の主要Origin) + CORS_ALLOWED_ORIGINS(カンマ区切りの
// 追加許可、ステージング等の複数フロントエンドがある場合のみ使用)で構成する。
//
// 【重要】このファイルは副作用のない純粋な配列生成のみを行う。
// `composer dump-autoload` (→ `artisan package:discover`) はDockerビルド時にも
// 実行され、その時点ではRenderのRuntime Environment Variables(FRONTEND_URL等)は
// まだ存在しないため、ここで例外を投げるとDockerビルド自体が失敗してしまう。
// production環境でFRONTEND_URL等が未設定であることの検証は、実行時(コンテナ起動時)に
// App\Support\ProductionEnvironmentValidator (php artisan app:validate-production-env)
// が行う。フォールバックはlocal/testingでのみ許容する(それ以外はvalidatorが検知する)。
$environment = (string) env('APP_ENV', 'production');

$frontendUrl = env('FRONTEND_URL');

if (blank($frontendUrl) && in_array($environment, ['local', 'testing'], true)) {
    $frontendUrl = 'http://localhost:3000';
}

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'up'],

    'allowed_methods' => ['*'],

    // supports_credentials=trueのため "*" は使用できない
    // (CorsOrigins::resolve()が"*"を常に除外するため、ここに紛れ込むことはない)。
    'allowed_origins' => CorsOrigins::resolve($frontendUrl, env('CORS_ALLOWED_ORIGINS')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

    // HandleCorsが読む標準キーではないが、ProductionEnvironmentValidatorが
    // 「FRONTEND_URL単体」の妥当性(有効なURLか等)を判定できるよう、
    // 正規化前の値をここに公開しておく(未知のキーとして無視されるだけで実害はない)。
    'frontend_url' => $frontendUrl !== null ? trim((string) $frontendUrl) : null,

];
