<?php

/*
|--------------------------------------------------------------------------
| 依頼CS(2026-10-10): トップメッセージと制度の「素材ページ(社内用・下書き)」
|--------------------------------------------------------------------------
| 保存済みのHTMLから、メッセージの候補の文と制度の候補の一覧を機械的に抜き出す。
| AIは使わない。抜き出した文字は言い換え・要約・つなぎ合わせをしない(長すぎるときだけ
| 末尾に「…」を付けて切る)。ページの選び方は config/top_message_insight.php の
| 語・条件(TopMessagePageFinder)をそのまま使う。
|
| ここの上限・語の一覧を、結果を良く見せるために調整しないこと。
*/

return [

    /*
    | 素材ページのスイッチ(既定true、依頼者の要望)。falseのとき素材ページは1枚も入らない。
    | top_message_insight.enabled(依頼CQのAI)が同時にtrueでも、素材ページだけを出す。
    */
    'enabled' => (bool) env('TOP_MESSAGE_DRAFT_ENABLED', true),

    /*
    | 制度のページの上限(TopMessagePageFinderが返す優先順の先頭から)。
    */
    'program_pages_max' => 6,

    /*
    | CS-2: メッセージの候補。
    |  - 文の長さ(文字)。範囲内の文を優先し、上限を超える文は末尾に「…」を付けて切った上で、範囲内の文の後ろに回す。
    |  - 見出し(h1〜h3)は長さの下限だけを見る(上限を超えたら同じく「…」で切る)。
    |  - 合計の上限、そのうち見出しに充てる上限。話し手の行は別枠で1行まで。
    */
    'message_sentence_min_chars' => 20,
    'message_sentence_max_chars' => 80,
    'message_heading_min_chars' => 2,
    'message_total_max' => 6,
    'message_headings_max' => 2,

    /*
    | 話し手の行: この語を含み、長さが上限以内で、除外語を含まない行。1行だけ。
    */
    'speaker_words' => ['代表取締役', '代表社員', '代表理事', '取締役社長', '社長', '会長', 'CEO', 'Founder'],
    'speaker_exclude_words' => ['メッセージ', 'message', '挨拶', 'インタビュー', '募集', '採用'],
    'speaker_max_chars' => 30,

    /*
    | メッセージの文として使わない文(正規表現)。
    */
    'sentence_exclude_patterns' => ['~©|copyright|cookie|クッキー|プライバシー|個人情報|利用規約|無断転載~iu'],

    /*
    | CS-3: 制度の候補。見出し(h2〜h4)・dt・th の文字の長さ(文字)と、直後の本文の抜粋の上限(文字)。
    | 1社あたりの上限。
    */
    'program_name_min_chars' => 2,
    'program_name_max_chars' => 25,

    /*
    | 依頼CSA-1: 末尾がこの文字の見出しは、制度の名前ではなく絞り込みや表の項目名の書き方なので、候補にしない
    | (文字の前後の空白を除いてから見る)。
    */
    'program_name_excluded_suffixes' => [':', '：'],
    'program_excerpt_max_chars' => 50,
    'program_total_max' => 12,

    /*
    | 汎用の見出し(制度の候補・メッセージの見出しから除く)。空白・記号を除き、大文字小文字を区別せず、
    | 全体が一致するものを除く。
    */
    'generic_headings' => [
        'お問い合わせ', 'お問合せ', '問い合わせ', 'ENTRY', 'エントリー', 'MENU', 'メニュー', '関連リンク', 'よくある質問', 'FAQ',
        'TOP', 'トップ', 'HOME', 'ホーム', 'NEWS', 'ニュース', 'お知らせ', 'RECRUIT', '採用情報', '採用サイト', 'COMPANY', '会社概要',
        'ABOUT', 'CONTACT', 'SITEMAP', 'サイトマップ', 'プライバシーポリシー', 'SNS', 'LINK', 'LINKS', 'PAGE TOP', 'ページトップ',
        'MESSAGE', 'メッセージ', '一覧', '一覧を見る', 'もっと見る', 'VIEW MORE', 'MORE', 'SEARCH', '検索', 'SHARE', 'シェア',
        '関連記事', '関連コンテンツ', 'おすすめ記事', 'あわせて読みたい', '項目', '内容',
        'ARCHIVE', 'アーカイブ', 'CATEGORY', 'カテゴリ', 'カテゴリー', 'TAG', 'タグ', '目次', 'INDEX',
    ],

    /*
    | 見出しを拾わない場所: これらのタグの内側、またはclass/idがこの正規表現に当たる要素の内側
    | (サイト共通のヘッダー・フッター・ナビ・サイドバー)。
    */
    'excluded_ancestor_tags' => ['header', 'footer', 'nav', 'aside', 'script', 'style', 'noscript', 'template'],
    'excluded_ancestor_roles' => ['navigation', 'banner', 'contentinfo', 'complementary'],
    'excluded_ancestor_class_pattern' => '~(^|[\s_-])(nav|navi|navigation|gnav|globalnav|menu|footer|header|sidebar|breadcrumb|breadcrumbs|drawer|hamburger)($|[\s_-])~i',
    /*
    |--------------------------------------------------------------------------
    | 依頼CZ-1: パネル(トップメッセージと制度)の材料の整え方(AIなし)。素材ページ(CS)の抜き出しとは別に使う。
    |--------------------------------------------------------------------------
    | 語・割合・上限をここに置く。会社ごとの語の一覧は作らない。
    */
    // メッセージの文として数える最小の長さ(文字)と、1文の最大の長さ(これを超える文は材料に入れない)。
    'panel_sentence_min_chars' => 10,
    'panel_sentence_max_chars' => 200,

    // この見出し(空白・記号を除き大文字小文字を区別せず、全体が一致)の下は、次の「同じか上の」見出しまで、メッセージの文から除く。
    'related_headings' => ['関連記事', '関連リンク', '関連コンテンツ', 'あわせて読みたい', 'おすすめ記事', 'RELATED', 'RELATED ARTICLES', 'RELATED LINKS', 'Related Posts'],

    // その要素の文字のうち、リンク(a)の中の文字がこの割合以上の文は、リンクの文字だけの文として除く(記事タイトルのリンクなど)。
    'link_text_ratio' => 0.6,

    // 制度の抜粋の上限(文字)。素材ページの50字とは別に、パネルでは全文(この長さまで)を材料にする。
    'panel_program_excerpt_max_chars' => 200,

    // 制度の候補の名前にこの文字を含むものは、一覧・職種のリンクの形で制度の名前ではないため除く(依頼CZ追補)。
    'program_name_excluded_chars' => ['｜', '|'],

    // 依頼CZ追補2: 制度でない行を除く。抜粋がこの文字数に満たない候補(職種名・ツール名だけの抜粋)と、
    // 名前がこの採用区分の言葉と一致する候補(一致で判定。含むだけでは除かない。記号・空白・大文字小文字は無視)。
    'program_excerpt_min_chars' => 15,
    'program_name_excluded_words' => ['新卒', '中途', 'キャリア', 'キャリア採用', '新卒採用', '中途採用', 'インターン', 'インターンシップ', 'アルバイト', 'パート', 'アルバイト・パート'],

    // 制度の候補を取るページのうち、ページ名またはURLのパスにこの語を含むページ(募集要項・求人一覧)の候補は、制度ではなく求人のため除く。
    'job_page_words' => ['募集要項', '求人', '募集職種', '職種一覧', '募集一覧', 'job-list', 'joblist', 'jobs', 'requirements', 'entry', 'job_list', 'positions'],

    // 依頼DH(2026-10-24): 記事・インタビュー・ブログのページ(URLのパスの各部分にこの語を含む)から取った候補は、制度ではなく
    // 記事の見出しや発言のため除く(本番のマネーフォワードで /times_mf/article/ の記事の小見出しが制度のカードになった)。
    /*
    | 依頼DI: メッセージの見出し・文のうち、この語だけでできているもの(「Top Message トップメッセージ」など)は、
    | 本文として使わない。空白・記号を除き、大文字小文字を区別せず、語を取り除くと何も残らないものを除く。
    */
    'message_label_words' => [
        'トップメッセージ', '代表メッセージ', '社長メッセージ', 'メッセージ', 'message', 'top', 'トップ',
        'ceo', 'president', 'greeting', 'ごあいさつ', 'ご挨拶', '挨拶', '代表挨拶', '社長挨拶', '代表', '社長',
    ],

    'article_page_path_words' => ['article', 'blog', 'interview', 'column', 'story', 'stories', 'magazine', 'journal'],

    // 依頼DH: 名前がこの記号で始まる候補(記事の小見出し「▶ …」など)と、抜粋がこの記号で始まる候補(インタビューの問い「— …」など)は除く。
    'program_name_excluded_leading_symbols' => ['▶', '►', '▷', '▸', '■', '□', '●', '◆', '◇', '★', '☆', '※', '→', '＞', '>', '【'],
    'program_excerpt_excluded_leading_symbols' => ['—', '―', '─', 'Q.', 'Q：', 'Q:', 'A.', 'A：', 'A:'],
];
