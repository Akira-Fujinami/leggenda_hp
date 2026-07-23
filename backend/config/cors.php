<?php

use App\Support\CorsOrigins;

// frontendとbackendを別Origin(別ドメイン)のRender Web Serviceとして運用するため、
// 許可Originは FRONTEND_URL(必須の主要Origin) + CORS_ALLOWED_ORIGINS(カンマ区切りの
// 追加許可、ステージング等の複数フロントエンドがある場合のみ使用)で構成する。
//
// production(および明示的な環境名を持つステージング等)ではFRONTEND_URLの設定を必須とし、
// 未設定のまま黙ってlocalhostへフォールバックすることはない
// (CorsOrigins::assertConfigured()が config:cache 実行時にも例外で検知する)。
// フォールバックはlocal/testingでのみ許容する。
$environment = (string) env('APP_ENV', 'production');

$frontendUrl = env('FRONTEND_URL');

if (blank($frontendUrl) && in_array($environment, ['local', 'testing'], true)) {
    $frontendUrl = 'http://localhost:3000';
}

$allowedOrigins = CorsOrigins::resolve($frontendUrl, env('CORS_ALLOWED_ORIGINS'));

CorsOrigins::assertConfigured($allowedOrigins, $environment);

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
    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
