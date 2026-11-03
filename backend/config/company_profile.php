<?php

/*
|--------------------------------------------------------------------------
| 依頼DK(2026-11-03): 「私たちが理解している、{社名}という会社」のページ(社内用・下書き)
|--------------------------------------------------------------------------
| 各社1ページ。トップメッセージの一文(見出し)と、会社を表す柱(3つまで)を、柱ごとに
| 「大切にしていること」「実績」「外からの評価」で並べる。
|
| 1) 材料(AIなし): トップメッセージのページの文(ID付き。依頼CZの材料と同じ)と、会社のサイトのURL
| 2) AI(Claude・1社1回): Web検索(Anthropic の web_search ツール)で会社のサイト・IR・プレスリリース・受賞の発表を調べ、
|    事実ごとに「出典のURL」と「そのページの原文の一節(quote)」を付けて返す
| 3) サーバーが、出典のURLを自分で取りに行き(SafeHttpFetcher)、原文の一節がそのページに本当にあるか、
|    事実の文の数字・「」の語が原文の一節にあるかを確かめる(CompanyProfileVerifier)。通らない事実は出さない(空欄)
|
| 最重要: 確認を緩めてAIの文を通さないこと。ここの閾値・一覧を変えて結果を良く見せないこと。
*/

return [

    /*
    | 起動のスイッチ(既定はfalse)。falseのときは、ジョブを出さない・AIを呼ばない・比較の完了も待たない・ページを入れない。
    */
    'enabled' => (bool) env('COMPANY_PROFILE_ENABLED', false),

    /* モデル名(コードに既定値を持たない)。APIキーは services.anthropic.api_key。 */
    'model' => (string) env('COMPANY_PROFILE_MODEL', ''),

    /* プロンプトの版。プロンプト本文(AnthropicCompanyProfileProvider::buildPrompt())を変えたら上げる。 */
    'prompt_version' => 'v1',

    /* 全社の巡回が終わってからこの分数を過ぎたら、比較の完了を待たない。 */
    'wait_max_minutes' => (int) env('COMPANY_PROFILE_WAIT_MAX_MINUTES', 20),

    /* AI呼び出しのHTTPタイムアウト(秒)・出力の上限・1社あたりのWeb検索の回数の上限・続きの呼び出し(pause_turn)の上限。 */
    'timeout' => (int) env('COMPANY_PROFILE_TIMEOUT', 180),
    'max_output_tokens' => (int) env('COMPANY_PROFILE_MAX_OUTPUT_TOKENS', 6000),
    'web_search_max_uses' => (int) env('COMPANY_PROFILE_WEB_SEARCH_MAX_USES', 8),
    'max_continuations' => 2,

    /* AIに渡すメッセージの文の数の上限。 */
    'prompt_sentences_max' => 40,

    /*
    |--------------------------------------------------------------------------
    | サーバー側の確認。長さは文字数
    |--------------------------------------------------------------------------
    */
    'headline_min_chars' => 8,
    'headline_max_chars' => 40,
    'lead_max_chars' => 70,
    'pillars_max' => 3,
    'pillars_min' => 2,
    'pillar_title_max_chars' => 30,
    'fact_max_chars' => 60,
    'quote_min_chars' => 8,
    'quote_max_chars' => 300,

    /* 出典を取りに行くときのタイムアウト(秒)と、受け付ける種類(PDFなどは確かめられないので通さない)。 */
    'source_fetch_timeout' => 15,
    'source_content_types' => ['text/html', 'application/xhtml+xml', 'text/plain'],

    /* 1社あたり、出典を取りに行くURLの数の上限(同じURLは1回だけ)。 */
    'source_fetch_max' => 15,

    /*
    | 「大切にしていること」は、その会社自身のサイト(採用サイト・コーポレートサイトと同じドメイン)の原文に限る。
    | 「実績」「外からの評価」は、会社のサイト・IR・報道発表・主催者の発表など、どのサイトでもよい(原文に一節があること)。
    */
    'value_requires_own_site' => true,

    /* 2段のドメイン(co.jp など)。会社のドメインを「名前.co.jp」として数える。 */
    'second_level_suffixes' => ['co.jp', 'or.jp', 'ne.jp', 'go.jp', 'ac.jp', 'ed.jp', 'lg.jp', 'gr.jp', 'ad.jp', 'co.uk', 'com.au', 'com.cn', 'com.tw', 'co.kr'],

    /*
    |--------------------------------------------------------------------------
    | スライドの文言・色(依頼DK)
    |--------------------------------------------------------------------------
    */
    'title' => '私たちが理解している、%sという会社',
    'band_label_self' => '御社のトップメッセージ',
    'band_label_competitor' => 'トップメッセージ',
    'band_no_message' => 'トップメッセージのページを確認できませんでした。',
    'labels' => [
        'value' => '大切にしていること',
        'achievement' => '実績',
        'recognition' => '外からの評価',
    ],
    'fact_missing' => '確認できませんでした',
    // 自社のページだけ、下に問いかけの1行を出す(空なら出さない)。
    'question_lead' => 'この理解で、合っていますか？',
    'question_text' => 'ズレている点や、外からは見えにくい御社の強みがあれば、ぜひ教えてください。',
    'source_prefix' => '出典：',
    'source_separator' => '／',
    // 依頼DL: 会社自身のサイトの出典をまとめる名前(%s=社名)。
    'source_own_label' => '%sのサイト',
    'source_max' => 6,
    'band_fill' => '252B35',
    'band_highlight' => 'E7B8C3',
    'card_fill' => 'F1F2F4',
    'accent' => 'A3262F',
];
