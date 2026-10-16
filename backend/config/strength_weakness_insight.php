<?php

/*
|--------------------------------------------------------------------------
| 依頼DB(2026-10-16): 比較ページの強み・弱みを、Claude が「根拠つきの2行」で書く(社内用・下書き)
|--------------------------------------------------------------------------
| 依頼CU〜CW(強み・弱みの一文。AIには引用だけを選ばせ、サーバーが文を組み立てる)とは別の機能。AIに解釈まで書かせ、
| サーバーの確認は事実の部分だけ(「」の言葉が原文にある・会社名・数字・長さ・強すぎる評価の言葉)に絞る。
| 確認に落ちた側は、その側だけ依頼CT-1の文に戻す(依頼CWの機能も有効なら、その文が先に戻り先になる)。
|
| 流れ: 材料(判定の○×・根拠の文・自社の採用TOPの構造・点数。AIを使わない) → Claudeを1回呼ぶ(温度は固定の値で送れないモデルがあるため、
|       config 'temperature' がnullのときは送らない) → サーバー側の確認(StrengthWeaknessInsightVerifier) → ファイルに置く。
| PPTXのダウンロード時にAIを呼ばない・通信しない(ファイルを読むだけ)。
|
| 判定(brand_wheel、プロンプトv9)・改善提案(v14)・巡回・判定のAI(OpenAI)とは無関係。
| 既存の config('strength_weakness_sentence')(依頼CW)とは別の設定。
*/

return [

    /*
    | 起動のスイッチ(既定はfalse)。falseのときは、ジョブを出さない・AIを呼ばない・比較の完了も待たない・
    | スライドは依頼CT-1の一文のまま(本番では環境変数を足さない限り何も起きない)。
    */
    'enabled' => (bool) env('STRENGTH_WEAKNESS_INSIGHT_ENABLED', false),

    /* enabled=trueのとき、全社の判定が終わってからこの分数を過ぎたら、このジョブを待たずに比較を完了にする(永久に待たないための保険)。 */
    'wait_max_minutes' => (int) env('STRENGTH_WEAKNESS_INSIGHT_WAIT_MAX_MINUTES', 10),

    /* プロンプトの版。プロンプト本文(AnthropicStrengthWeaknessInsightProvider)を変えたら上げる。結果のファイルに記録する。 */
    'prompt_version' => 'v1',

    /*
    | Claude(Anthropic Messages API)の呼び出し。APIキーは services.anthropic.api_key(ANTHROPIC_API_KEY)。
    | モデル名は env(STRENGTH_WEAKNESS_INSIGHT_MODEL)で渡す。既定値は持たない(未設定のときは、AIを呼ばず依頼CT-1の文)。
    | temperature: nullのときは送らない。Claude Sonnet 5.5 など新しいモデルは、temperature・top_p・top_k に既定以外の値を
    | 送ると400になる(公式ドキュメント)。送れるモデルを使うときだけ、env で低い値(例: 0)を渡す。
    */
    'model' => env('STRENGTH_WEAKNESS_INSIGHT_MODEL'),
    'timeout' => (int) env('STRENGTH_WEAKNESS_INSIGHT_TIMEOUT', 120),
    'max_retries' => (int) env('STRENGTH_WEAKNESS_INSIGHT_MAX_RETRIES', 1),
    // 考える分の出力も含めた上限(Claudeの新しいモデルは、答えの前に考える)。
    'max_output_tokens' => (int) env('STRENGTH_WEAKNESS_INSIGHT_MAX_OUTPUT_TOKENS', 4000),
    'temperature' => env('STRENGTH_WEAKNESS_INSIGHT_TEMPERATURE') !== null && env('STRENGTH_WEAKNESS_INSIGHT_TEMPERATURE') !== ''
        ? (float) env('STRENGTH_WEAKNESS_INSIGHT_TEMPERATURE')
        : null,

    /*
    |--------------------------------------------------------------------------
    | 材料(DB-2)。上限は文字数。超えたら、根拠の文を後ろから削る(競合の根拠の文 → 自社の根拠の文)。
    |--------------------------------------------------------------------------
    */
    'material_max_chars' => 16000,

    /* 自社の採用TOPの構造を、プロンプトに載せるときの1行の上限(文字)。 */
    'structure_text_max_chars' => 80,

    /*
    |--------------------------------------------------------------------------
    | サーバー側の確認(DB-4。事実の部分だけ)
    |--------------------------------------------------------------------------
    */
    /* 強み・弱みそれぞれの長さの上限(文字。比較ページで2行に収まる長さ)。 */
    'text_max_chars' => 100,

    /* 文に出る「」の言葉は、quotes のどれかと一致しなければならない。文に「」が1つも無い(=根拠の引用が無い)側も、落とす。 */
    'require_quote' => true,

    /* quotes の company が自社を指す呼び方(自社の名前のほかに、これも自社として扱う)。 */
    'self_aliases' => ['自社'],

    /* 会社名を探すときの法人格(文に「株式会社〇〇」の形で出る名前が、比較に入っている会社かを確かめる)。 */
    'company_legal_forms' => ['株式会社', '有限会社', '合同会社', '合名会社', '合資会社', '一般社団法人', '一般財団法人', '公益社団法人', '公益財団法人'],

    /* 強すぎる評価の言葉。含む側は落とす(依頼CUの禁止の言葉の一覧は使わない)。 */
    'strong_evaluation_words' => ['最悪', 'ひどい', '失格', '致命的', '論外', '絶望的', '壊滅的', '最低', 'ダメ', '話にならない'],

    /*
    |--------------------------------------------------------------------------
    | スライド(DB-5)
    |--------------------------------------------------------------------------
    */
    /* AIの文を使った側の行の頭に付ける小さな赤いラベル。 */
    'label' => 'AI下書き',
    'label_color' => 'C0392B',
    'label_font_size' => 7,

    /* AIの文を使ったときだけ、ページの下に足す注記。 */
    'note' => '強み・弱みは、各社サイトの記述をもとに AI が作成した下書きです。お客様にお見せする前に、内容を確認・修正してください。',

    /*
    | AIの文を使うときの文字の大きさ(pt)と、1行に入る字数(基準の大きさのとき。行の頭のラベルを含む)。強み・弱みそれぞれ2行まで。
    | 2行ぶんの字数を超える側があるときだけ、文字を小さくする(下限あり)。
    | 合わせて2行(各1行)に収まるときは、従来の位置のまま。超えるときは、強み・弱みの箱の上端を layout_raise_in(in)だけ上げ、
    | それでも必要な高さ(行数 × 文字の大きさ × 1.25)に足りない分だけ、見出し以下の列を下へずらす(layout_gap_in は、箱の下と見出しの間に空ける量)。
    */
    'font_size' => 8,
    'min_font_size' => 7.5,
    'line_chars' => 102,
    'layout_raise_in' => 0.12,
    'layout_gap_in' => 0.04,
];
