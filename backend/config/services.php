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
        // 2026-08-03: 既定値を未設定(無制限)から6000tokenへ変更。ブランド・
        // ホイールの固定プロンプト部分(フレームワーク定義+実例+教示事項)が
        // 約2,700token、本文の多い実サイト1件でも通常はこれを大きく超えない
        // ―― 6000tokenは概算で本文+ナビラベル合計約18,000文字相当
        // (BrandWheelAnalysisInputFactory::applyTokenLimit()の
        // 「文字数≒token数×3」という同じ概算を使用)にあたり、実際の採用
        // ページ・トップページ本文(通常数百〜数千文字)を十分に上回りつつ、
        // 極端に文章量の多い外れ値サイト1件で際限なくコスト・コンテキストが
        // 膨らむことを防ぐ上限として機能する。超過時は既存の切り詰め処理
        // (採用ページ本文を優先して残し、input_truncated=trueを記録)が働く。
        // env未設定でも必ずこの上限が効く(2026-08-03のユーザー指摘: 「無制限」を
        // 既定にしない)。
        'max_input_tokens' => env('AI_MAX_INPUT_TOKENS') !== null && env('AI_MAX_INPUT_TOKENS') !== ''
            ? (int) env('AI_MAX_INPUT_TOKENS')
            : 6000,
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
        // 2026-08-04: モデル比較測定用に追加。未設定時はopenai.model
        // (=OPENAI_MODEL、既定gpt-4o-mini)をそのまま使うため、既存の挙動は
        // 変えない。入力・プロンプト(teaching_points/examples/caveat)・
        // temperatureはこの測定では一切変更しないこと。
        //
        // 本番はgpt-4o固定(.env.example参照)。gpt-4o-miniへコスト削減目的で
        // 下げないこと ―― プロンプト中のsub_element_definitions(定義文)を
        // そのままevidenceとして返す挙動がgpt-4o-miniで高頻度に発生し、同一
        // 入力でも判定結果が実行ごとに変わることを実測で確認済み(2026-08実測:
        // 同一サイト同一入力でmatchedが3/0/3、gpt-4oでは8/8/8で完全安定。
        // BrandWheelDiscardedSubElementのdefinition_echo理由で観測できる)。
        'model' => env('BRAND_WHEEL_AI_MODEL'),
        'timeout' => (int) env('BRAND_WHEEL_AI_TIMEOUT', env('AI_TIMEOUT', 60)),
        'max_retries' => (int) env('BRAND_WHEEL_AI_MAX_RETRIES', env('AI_MAX_RETRIES', 1)),
        'max_output_tokens' => (int) env('BRAND_WHEEL_AI_MAX_OUTPUT_TOKENS', env('AI_MAX_OUTPUT_TOKENS', 2000)),
        // ブランド・ホイールは判定システムであり、同一サイトを2回評価して
        // 違う結果が出ることは社外に出す文章として受け入れられない。
        // 設定可能な最小値(0.0)を既定にし、揺らぎを最小化する
        // (2026-07-30の指摘。既存のOpenAiAnalysisProvider(スコアリング要約用)の
        // temperature=0.2はそのままで良い ―― あちらは判定システムではない)。
        'temperature' => (float) env('BRAND_WHEEL_AI_TEMPERATURE', 0.0),

        // 依頼U(2026-08-26): レート制限(429)等でrelease()により再試行する
        // job-level(queue経由)のリトライ回数・バックオフ・時間上限。
        // 上のmax_retriesとは別物 ―― あちらは1回のジョブ試行内でのHTTP
        // レベルの再送(408/504・接続エラー、OpenAiBrandWheelAnalysisProvider::
        // request()参照)、こちらはジョブ全体をqueueへ戻して後で再実行する
        // 回数。5件同時実行時にOpenAIのレート制限へ当たっても「失敗」では
        // なく「少し遅い」で済ませるための設定(依頼T調査結果: 5件同時で
        // 短時間に約12万トークン相当のAI呼び出しが集中しうる。依頼Fの実測
        // では約3万トークンでレート制限に到達している)。
        // GenerateBrandWheelAnalysisJob/GenerateBrandWheelImprovementSuggestion
        // Jobの両方が参照する(同じ方針を適用、依頼者指定)。
        //
        // 初期値の根拠: [30, 90, 180]は現状の30秒固定から段階的に伸ばす
        // (Laravel自身のWorker::calculateBackoff()と同じ「配列の最後の値を
        // 以降の試行で繰り返す」規則)。tries=4・backoffの累計待ち時間は
        // 30+90+180=300秒(5分)で、job_retry_until_minutes(既定10分)・
        // config('lead.stale_analysis_after_minutes')(30分)のいずれにも
        // 十分な余裕を残す。実際の待ち時間はOpenAIのRetry-Afterヘッダが
        // 優先されるため、これより短いことも長いこともある。
        'job_tries' => (int) env('BRAND_WHEEL_AI_JOB_TRIES', 4),
        // カンマ区切りの環境変数からPHP配列へ変換する
        // (config('brand_wheel.crawl_excluded_path_patterns')と同じ方式)。
        // 空・不正な値しか無い場合は[30]にフォールバックする。
        'job_backoff_seconds' => array_map('intval', array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('BRAND_WHEEL_AI_JOB_BACKOFF_SECONDS', '30,90,180')),
        )))) ?: [30],
        // retryUntil()の時間上限(分)。config('lead.stale_analysis_after_minutes')
        // (30分)より明確に短くすること ―― これが無いと、レート制限が
        // 続いた場合に診断がstale判定の枠(LeadAnalysisController::
        // isCongested()等)を長時間占有し続ける(依頼者指定)。job_tries×job_backoff_
        // secondsの累計より余裕を持たせつつ、stale判定の1/3以下に収める
        // 目安として10分とした。
        'job_retry_until_minutes' => (int) env('BRAND_WHEEL_AI_JOB_RETRY_UNTIL_MINUTES', 10),
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
