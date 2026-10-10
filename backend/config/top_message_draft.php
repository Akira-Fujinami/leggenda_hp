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
];
