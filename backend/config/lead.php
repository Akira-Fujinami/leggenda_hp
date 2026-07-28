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

    // リード分析でもLighthouse/Semrushの両方を実施し、社内向けと同等のスコア
    // 精度を確保する(Phase 2でSemrush併用が承認されたことに伴い、Phase 1の
    // 「Lighthouse省略」判断を撤回。既定はfalseで、Analyzer単一Workerの
    // 占有時間が伸びる点は max_concurrent_analyses の抑制で吸収する)。
    'skip_lighthouse' => (bool) env('LEAD_SKIP_LIGHTHOUSE', false),

    // リード分析ではスクリーンショット撮影を省略する。77指標のうち
    // スクリーンショット由来の指標は0件のため、採点への影響はない。
    // 社内向けフル機能は常にfalse相当(撮影する)のまま変わらない。
    'skip_screenshots' => (bool) env('LEAD_SKIP_SCREENSHOTS', true),

    // lead_sessions/その配下データの保持期間(日数)。有効期限切れから
    // この日数を過ぎたセッションを lead:purge-expired-sessions --execute で削除する。
    'retention_days_after_expiry' => (int) env('LEAD_RETENTION_DAYS_AFTER_EXPIRY', 180),

    // 診断開始通知・相談リクエスト通知の送信先。個人のメールアドレスではなく
    // 必ず共有の受信箱を指定すること ―― 通知本文にはリード自身のトークンを
    // 使った「診断結果を開く権限つきリンク」を含めるため、個人宛だと転送時に
    // 権限が漏れるリスクがある(2026-07-28の合意事項)。
    'notification_to' => env('LEAD_NOTIFICATION_TO'),
];
