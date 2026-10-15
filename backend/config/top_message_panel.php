<?php

/*
|--------------------------------------------------------------------------
| 依頼CZ(2026-10-15): トップメッセージと制度のパネル ―― AI は「選ぶ」だけ、文はサーバーが確かめる
|--------------------------------------------------------------------------
| 1) 材料(AIなし): メッセージのページの文(ID付き)と、制度の候補(ID・名前・抜粋)を、保存済みのHTMLから機械的に整える
|    (TopMessagePanelMaterialBuilder。語・上限は config/top_message_draft.php の panel_*)
| 2) AI(1社1回・温度0): 核の一文・本文の文・キーワード(メッセージの文から抜き出した語句)・キーワードごとの制度と、その制度の
|    抜粋から抜き出した一節を「選ばせる」。文章は書かせない
| 3) 選んだものを、サーバーが原文にあるか確かめる(TopMessagePanelVerifier)。通ったものだけをスライドに出す。
|    キーワードが min 個に満たない・核の一文が落ちたときは、パネルを使わず、依頼CSの素材ページ(機械の並べ方)のまま
|
| 最重要: 確認を緩めてAIの選択を通さないこと。ここの閾値・一覧を変えて結果を良く見せないこと。
| 判定(brand_wheel、プロンプトv9)・改善提案(v14)・巡回とは無関係。AIの呼び出し先(プロバイダ・モデル)は
| config('services.brand_wheel_ai')を共用する(温度は0)。禁止の言葉の一覧は依頼CUと同じ config('comparison_commentary.forbidden_words')、
| 説明の末尾の禁止は依頼CWと同じ config('strength_weakness_sentence.quote_bad_endings')。
*/

return [

    /*
    | 起動のスイッチ(既定はfalse)。falseのときは、ジョブを出さない・AIを呼ばない・比較の完了も待たない・
    | スライドは依頼CSの素材ページのまま(本番では環境変数を足さない限り何も起きない)。
    | 素材ページ(top_message_draft.enabled)がfalseのときは、パネルも出さない。
    */
    'enabled' => (bool) env('TOP_MESSAGE_PANEL_ENABLED', false),

    /*
    | enabled=trueのとき、診断全体(Analysis)の完了は、全社の巡回が終わったあとに出すこのジョブが終わるまで待つ。
    | ジョブが失敗しても完了にはする。永久に終わらないときに備え、全社が終わってからこの分数を過ぎたら待たない。
    */
    'wait_max_minutes' => (int) env('TOP_MESSAGE_PANEL_WAIT_MAX_MINUTES', 15),

    /* プロンプトの版。プロンプト本文(OpenAiTopMessagePanelProvider)を変えたら上げる。結果のファイルに記録する。 */
    'prompt_version' => 'v1',

    /* AI呼び出しのHTTPタイムアウト(秒)と出力トークンの上限(1社1回)。 */
    'timeout' => (int) env('TOP_MESSAGE_PANEL_TIMEOUT', 90),
    'max_output_tokens' => (int) env('TOP_MESSAGE_PANEL_MAX_OUTPUT_TOKENS', 1500),

    /*
    |--------------------------------------------------------------------------
    | サーバー側の確認(CZ-3)。長さは文字数
    |--------------------------------------------------------------------------
    */
    'core_min_chars' => 15,
    'core_max_chars' => 80,

    /* 本文の文: 核を補う文の数の上限。 */
    'body_max' => 3,

    /* キーワード(メッセージの文からそのまま抜き出した語句)の長さと、残す数。min個に満たなければ、パネルは使わない。 */
    'keyword_min_chars' => 2,
    'keyword_max_chars' => 20,
    'keywords_min' => 2,
    'keywords_max' => 4,

    /* 1つのキーワードに付ける制度の数の上限。 */
    'programs_per_keyword_max' => 3,

    /* 制度の説明(制度の抜粋からそのまま抜き出した一節)の長さ。 */
    'summary_min_chars' => 8,
    'summary_max_chars' => 30,

    /*
    |--------------------------------------------------------------------------
    | 文言(プロンプトに渡す語)
    |--------------------------------------------------------------------------
    */
    /* AIに渡す材料の数の上限(プロンプトの大きさを抑えるため)。ページの上の順に取る。 */
    'prompt_sentences_max' => 60,
    'prompt_programs_max' => 40,
];
