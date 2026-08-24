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

    // 2026-08-22追加: 停止した診断(Worker停止・OOM・例外で終端処理に到達
    // できず、Analysis.statusがPending/Queued/Runningのまま残ったもの)を、
    // hasAnalysisInProgress()(トークン単位の多重実行ガード)・isCongested()
    // (全体の同時実行数ガード)の両方で「対象外」とみなすまでの経過時間
    // (分、Analysis.created_at基準)。消費(analyses_used)をAnalysis開始時
    // ではなく自社サイトの本文取得成功時点へ遅らせたことで、停止した
    // Analysisが永久に居座ると、そのリードは409で、max_concurrent_analyses=1
    // の場合は全リードが503で、それぞれ無期限にブロックされ得るようになった
    // (依頼者指摘)。実測分布(#99、completed 平均108秒/最大127秒、
    // partial 平均411秒/最大801秒)の最大値(partial 801秒 ≒ 13.4分)に対し
    // 約2.25倍の安全マージンを取った値。「生きている低速な診断」を誤って
    // stale扱いする(=二重実行を許してしまう)ことの実害の方が、「stuckな
    // Analysisが最大30分居座る」ことの実害より大きいと判断し、安全側
    // (長め)に倒した。急ぎ復旧させたい場合は管理画面から手動で強制終了
    // できる(CompanyController::forceTerminateAnalysis())。
    'stale_analysis_after_minutes' => (int) env('LEAD_STALE_ANALYSIS_AFTER_MINUTES', 30),

    // リード分析ではLighthouseを省略する(2026-08-18、Phase 2の「省略しない」
    // 判断を再度撤回)。本番実測(#99): run_lighthouse/run_recruit_lighthouseの
    // 合計が1診断(自社+比較)あたり約106秒で、診断全体の平均108秒のほぼ全て
    // を占めていた。本番は全キュー(analysis/external-api/analysis-heavy/ai/
    // reports/notifications)を単一Workerが直列処理する構成のため、この間
    // AI分析・レポート生成も含め他の処理が全て待たされる。
    // 影響の裏取り(2026-08-18、実データ website_analysis_id=352で比較):
    // リード向けスコアが26→20点(45点満点)、カバー率88.7%→64.5%に低下し、
    // レポートの④観点(見やすさ・使いやすさ)の一文が「改善の余地があります」
    // (needs_improvement)から「確認をおすすめします」(needs_review)に変わる
    // ―― 画面(lead-results.tsx)は元々perspectives/scoreを表示していないため
    // 無影響だが、PDF/Wordレポートの文言・点数は変わる。この差分は許容する
    // 前提での変更(依頼者確認済み)。
    // 元に戻す場合はLEAD_SKIP_LIGHTHOUSE=falseを設定するだけでよい
    // (RunLighthouseJob/RunRecruitLighthouseJob自体は削除していない)。
    'skip_lighthouse' => (bool) env('LEAD_SKIP_LIGHTHOUSE', true),

    // リード分析ではスクリーンショット撮影を省略する。77指標のうち
    // スクリーンショット由来の指標は0件のため、採点への影響はない。
    // 社内向けフル機能は常にfalse相当(撮影する)のまま変わらない。
    'skip_screenshots' => (bool) env('LEAD_SKIP_SCREENSHOTS', true),

    // lead_sessions/その配下データの保持期間(日数)。有効期限切れから
    // この日数を過ぎたセッションを lead:purge-expired-sessions --execute で削除する。
    'retention_days_after_expiry' => (int) env('LEAD_RETENTION_DAYS_AFTER_EXPIRY', 180),

    // 自社URL到達性チェック(#B-1、LeadAnalysisController::store())専用の
    // タイムアウト秒数。同期処理(リードが送信ボタンを押したまま待つ)のため、
    // 本番の実取得(config('analysis.http.total_timeout_seconds')、既定20秒)
    // より短く設定する。超過した場合も現状どおり「通す」側へ倒すため
    // (保守的判定)、短くしても誤って診断をブロックすることはない。
    'self_url_reachability_check_timeout_seconds' => (int) env('LEAD_SELF_URL_REACHABILITY_CHECK_TIMEOUT_SECONDS', 6),

    // 診断開始通知・相談リクエスト通知の送信先。個人のメールアドレスではなく
    // 必ず共有の受信箱を指定すること ―― 通知本文にはリード自身のトークンを
    // 使った「診断結果を開く権限つきリンク」を含めるため、個人宛だと転送時に
    // 権限が漏れるリスクがある(2026-07-28の合意事項)。
    'notification_to' => env('LEAD_NOTIFICATION_TO'),
];
