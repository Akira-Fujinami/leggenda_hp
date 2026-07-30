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
        // 実装済み: 'mock', 'openai'。'anthropic'は将来対応予定で、
        // 現時点で指定するとAiAnalysisProviderFactoryが明確な設定エラーを投げる
        // (黙ってmockへフォールバックしない)。
        'provider' => env('AI_PROVIDER', 'mock'),
        'timeout' => (int) env('AI_TIMEOUT', 60),
        'max_retries' => (int) env('AI_MAX_RETRIES', 1),
        'max_input_tokens' => env('AI_MAX_INPUT_TOKENS') !== null && env('AI_MAX_INPUT_TOKENS') !== ''
            ? (int) env('AI_MAX_INPUT_TOKENS')
            : null,
        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 2000),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],

    // ブランド・ホイール(6軸)分析用のAI Provider設定。既存のai.provider
    // (スコアリング結果のAI要約、相談ボタンとは独立に都度呼ばれる)とは
    // 別のオペレーション設定として切り替え可能にする ―― 段階的ロールアウト中、
    // 一方だけmock/openaiを切り替えたい場面があるため。APIキー・モデル・
    // base_urlは同じOpenAIアカウントを使うため openai.* をそのまま流用する。
    'brand_wheel_ai' => [
        'provider' => env('BRAND_WHEEL_AI_PROVIDER', env('AI_PROVIDER', 'mock')),
        'timeout' => (int) env('BRAND_WHEEL_AI_TIMEOUT', env('AI_TIMEOUT', 60)),
        'max_retries' => (int) env('BRAND_WHEEL_AI_MAX_RETRIES', env('AI_MAX_RETRIES', 1)),
        'max_output_tokens' => (int) env('BRAND_WHEEL_AI_MAX_OUTPUT_TOKENS', env('AI_MAX_OUTPUT_TOKENS', 2000)),
        // ブランド・ホイールは判定システムであり、同一サイトを2回評価して
        // 違う結果が出ることは社外に出す文章として受け入れられない。
        // 設定可能な最小値(0.0)を既定にし、揺らぎを最小化する
        // (2026-07-30の指摘。既存のOpenAiAnalysisProvider(スコアリング要約用)の
        // temperature=0.2はそのままで良い ―― あちらは判定システムではない)。
        'temperature' => (float) env('BRAND_WHEEL_AI_TEMPERATURE', 0.0),
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
