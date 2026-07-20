<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'analyzer' => [
        'url' => env('ANALYZER_URL', 'http://analyzer:3001'),
        'token' => env('ANALYZER_TOKEN'),
    ],

    'ai' => [
        // 'mock'のみ実装済み。'openai'/'anthropic'は将来対応予定で、
        // 現時点で指定するとAiAnalysisProviderFactoryが明確な設定エラーを投げる
        // (黙ってmockへフォールバックしない)。
        'provider' => env('AI_PROVIDER', 'mock'),
        'api_key' => env('AI_API_KEY'),
    ],

    'semrush' => [
        'api_key' => env('SEMRUSH_API_KEY'),
        'database' => env('SEMRUSH_DATABASE', 'us'),
        'base_url' => env('SEMRUSH_BASE_URL', 'https://api.semrush.com'),
        // backlinks_overview等、Semrushの一部レポートは別ベースURL
        // (Analytics API v1系)を使う契約がある。未設定時はbase_urlを使う。
        'analytics_base_url' => env('SEMRUSH_ANALYTICS_BASE_URL', ''),
        'timeout' => (int) env('SEMRUSH_TIMEOUT', 30),
        'max_retries' => (int) env('SEMRUSH_MAX_RETRIES', 1),
        'daily_unit_limit' => env('SEMRUSH_DAILY_UNIT_LIMIT') !== null && env('SEMRUSH_DAILY_UNIT_LIMIT') !== ''
            ? (int) env('SEMRUSH_DAILY_UNIT_LIMIT')
            : null,
    ],

];
