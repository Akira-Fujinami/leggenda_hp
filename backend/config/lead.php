<?php

return [

    /*
    |--------------------------------------------------------------------------
    | リード向けセルフ診断機能の設定
    |--------------------------------------------------------------------------
    | 社内向けフル機能(config/analysis.php の max_websites_per_analysis 等)とは
    | 独立して管理する。ここを変更しても社内向け機能には一切影響しない。
    */

    // リード1件の診断に使えるサイト数上限(自社+競合1件)。
    // config('analysis.max_websites_per_analysis')(社内版5件)とは独立。
    'max_websites' => (int) env('LEAD_MAX_WEBSITES', 2),

    // ワンタイムトークンの有効期限(日数)。
    'token_expiry_days' => (int) env('LEAD_TOKEN_EXPIRY_DAYS', 7),

    // 1トークンあたりに許可する分析実行回数。
    'max_analyses_per_token' => (int) env('LEAD_MAX_ANALYSES_PER_TOKEN', 1),

    // 同時に実行中(running)でよいリード分析の件数上限。超過時は
    // 「現在混み合っています」を返し、トークンの実行回数を消費しない。
    // Analyzerの同時実行数(ANALYZER_MAX_CONCURRENCY、既定1)を踏まえ、
    // 安易に引き上げないこと(OOM再発のリスクがある)。
    'max_concurrent_analyses' => (int) env('LEAD_MAX_CONCURRENT_ANALYSES', 1),

    // リード分析ではLighthouseを省略し、Analyzerの単一Workerの占有時間を
    // 短縮する(実測: 含めると約72-79秒、省略すると約53秒)。
    'skip_lighthouse' => (bool) env('LEAD_SKIP_LIGHTHOUSE', true),

    // lead_sessions/その配下データの保持期間(日数)。有効期限切れから
    // この日数を過ぎたセッションを lead:purge-expired-sessions --execute で削除する。
    'retention_days_after_expiry' => (int) env('LEAD_RETENTION_DAYS_AFTER_EXPIRY', 180),
];
