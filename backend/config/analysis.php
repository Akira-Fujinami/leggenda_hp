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
    | 管理者起点の多社比較(依頼AB、2026-08-27追加)
    |--------------------------------------------------------------------------
    | 管理画面から、無料診断を起点に自社+競合3〜5社で実行する比較機能
    | (App\Services\Admin\AdminComparisonService)。件数の上下限を再デプロイ
    | 無しで調整できるようにする(依頼A/U/V等と同じ方針)。管理者専用の機能で
    | あり、config('lead.max_websites')(既定2、リード向け)とは独立。
    | max_websites_per_analysis(既定5)より競合数+自社1件の合計が大きくなる
    | ことがあるため、AdminComparisonServiceはAnalysisService::start()へ
    | 明示的にmax_websitesを渡す(config('analysis.max_websites_per_analysis')の
    | 既定に頼らない)。
    */
    'admin_comparison' => [
        'min_competitors' => (int) env('ADMIN_COMPARISON_MIN_COMPETITORS', 3),
        'max_competitors' => (int) env('ADMIN_COMPARISON_MAX_COMPETITORS', 5),
        /*
        | 依頼BP-2(2026-09-10): 比較ウィザードSTEP 2(会社名で診断を探す)の
        | 検索結果の上限。依頼者提案の「20件程度」を採用 ―― ウィザードの
        | STEP 2はスクロールなしで見比べて選べる件数を想定した一覧UIであり、
        | 20件は候補選択の画面としてまだ一覧性を保てる上限として妥当と判断した。
        | 件数を数えるだけの追加クエリを避けるため+1件で判定する
        | (ComparisonController::search()参照)。
        |
        | 依頼BX-2(2026-09-11): 超えた場合に候補を1件も返さない仕様は、
        | 19件では普通に出るのに20件を1件超えた瞬間に手がかりがゼロになり
        | 不親切だった(依頼者指摘、実運用で発生)。上限(この値)自体は
        | 変えず、先頭この件数ぶんを返したうえでtruncatedフラグを立てる形に
        | 直した。
        */
        'search_result_limit' => (int) env('ADMIN_COMPARISON_SEARCH_RESULT_LIMIT', 20),

        /*
        | 依頼BX-3(2026-09-11): 比較ウィザードSTEP 2の候補一覧に、
        | Analysis.statusの内部値(completed/partial等)をそのまま出さない。
        | 診断の状態の値自体は増やさず、表示文言だけをここに集約する
        | (wizard.blade.phpのJSがこのマップを@json()で受け取り、
        | クライアント側で変換する ―― ComparisonController::search()の
        | JSONに新しいフィールドを足さない、依頼者指定の範囲を守るため)。
        */
        'search_result_status_labels' => [
            'pending' => '実行待ち',
            'queued' => '待機中',
            'running' => '実行中',
            'completed' => '完了',
            'partial' => '一部完了',
            'failed' => '失敗',
            'cancelled' => '中止',
        ],
    ],

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

    /*
    |--------------------------------------------------------------------------
    | SSRFテスト許可リスト (E2Eテスト専用)
    |--------------------------------------------------------------------------
    | E2Eテストでローカルfixtureサイト(Docker Compose内の専用サービス)へ
    | 分析対象として到達させるための例外リスト。"host" または "host:port" を
    | カンマ区切りで指定する。production環境では(この値が設定されていても)
    | 常に無視する ―― analyzer側のSSRF_TEST_ALLOWLISTと同じ設計方針。
    */
    'ssrf_test_allowlist' => env('SSRF_TEST_ALLOWLIST', ''),
];
