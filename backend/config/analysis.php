<?php

return [

    /*
    |--------------------------------------------------------------------------
    | クロール方針
    |--------------------------------------------------------------------------
    */
    'crawler_user_agent' => env('CRAWLER_USER_AGENT', 'WebsiteComparisonBot/0.1 (+https://example.com/bot)'),

    // 1サイトに対する分析ジョブ間の最小間隔 (ミリ秒)。過剰アクセス防止。
    'per_site_min_interval_ms' => (int) env('ANALYSIS_PER_SITE_INTERVAL_MS', 1000),

    /*
    |--------------------------------------------------------------------------
    | HTTP取得の安全設定 (SafeHttpFetcher)
    |--------------------------------------------------------------------------
    */
    'http' => [
        'connect_timeout_seconds' => 10,
        'total_timeout_seconds' => 20,
        'max_redirects' => 3,
        'max_response_bytes' => 5 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | ストレージ
    |--------------------------------------------------------------------------
    */
    'storage_disk' => 'analysis',

    /*
    |--------------------------------------------------------------------------
    | 分析対象の上限
    |--------------------------------------------------------------------------
    */
    'max_websites_per_analysis' => 5,

    /*
    |--------------------------------------------------------------------------
    | 外部SEOデータ (Semrush等)
    |--------------------------------------------------------------------------
    | 'mock': 開発環境向けの決定論的な擬似データ (is_mock=trueで返す)。
    | 'semrush': 実際のSemrush APIを呼び出す。SEMRUSH_API_KEY必須。
    */
    'seo_provider' => env('SEO_PROVIDER', 'mock'),

    // ExternalDataSnapshotのキャッシュ有効期間 (時間)。SEMRUSH_CACHE_TTL_HOURSを
    // 優先し、未設定なら後方互換のためEXTERNAL_DATA_CACHE_HOURSにフォールバックする。
    'external_data_cache_hours' => (int) env('SEMRUSH_CACHE_TTL_HOURS', env('EXTERNAL_DATA_CACHE_HOURS', 24)),

    /*
    |--------------------------------------------------------------------------
    | Mock Provider(SEO/AI)の許可
    |--------------------------------------------------------------------------
    | production環境ではAPP_ENV判定により常にMock Providerを拒否する
    | (この設定値に関わらず)。それ以外の環境(local/testing)でも、通常起動時に
    | 意図せずMockが使われないよう、明示的にtrueを設定した場合のみ許可する。
    */
    'allow_mock_providers' => (bool) env('ALLOW_MOCK_PROVIDERS', false),
];
