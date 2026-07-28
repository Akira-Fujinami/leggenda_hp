<?php

namespace App\Support\Lead;

/**
 * リード(採用担当)向け表示専用の対訳・グルーピング定義。
 *
 * metric_definitions.nameは書き換えない ―― 社内向けフル機能の画面表示に
 * 影響するため。ここはリード向け結果画面・レポートだけが参照する、
 * 完全に独立した表示レイヤーのマスタである。
 *
 * 4観点(①書くべきこと・②メッセージ・③導線・④見やすさ)に含めない指標
 * (HTTPS・canonical・構造化データ・OGP・robots.txt・sitemap.xml・
 * CMS/解析タグ検出・外部SEO等)はこのカタログに一切登場しない
 * ―― マッピングが無いキーは自動的に「非表示」として扱う
 * (perspectiveFor()がnullを返す)。採点への寄与は従来どおり変わらない。
 */
class LeadMetricCatalog
{
    public const PERSPECTIVE_COMPLETENESS = 'completeness';

    public const PERSPECTIVE_CLARITY = 'clarity';

    public const PERSPECTIVE_FINDABILITY = 'findability';

    public const PERSPECTIVE_USABILITY = 'usability';

    /**
     * 表示順を兼ねた4観点の見出し。
     *
     * @var array<string, string>
     */
    public const PERSPECTIVE_LABELS = [
        self::PERSPECTIVE_COMPLETENESS => '書くべきことが書けているか',
        self::PERSPECTIVE_CLARITY => 'メッセージの分かりやすさ',
        self::PERSPECTIVE_FINDABILITY => '情報の取りやすさ・導線',
        self::PERSPECTIVE_USABILITY => '見やすさ・使いやすさ',
    ];

    /**
     * 採用担当が実際に持つ問いをそのまま見出しにする表示専用の文言
     * (2026-07-28のユーザー指摘: 画面とレポートで見出しが食い違うと
     * 商談の場で「どちらが正しいのか」という無用な疑問を生む)。
     *
     * PERSPECTIVE_LABELSは達成率の説明文(ReportSummaryComposerの
     * 「〜は達成率◯%にとどまっており」等、社内的な文章表現)で
     * 引き続き使うため変更しない ―― こちらは画面の見出し・
     * Word/PDFレポートの見出しの両方が共有する唯一の定義元とする。
     */
    public const PERSPECTIVE_HEADINGS = [
        self::PERSPECTIVE_COMPLETENESS => '書くべきことが書けているか',
        self::PERSPECTIVE_CLARITY => '伝えたいことが分かりやすく伝わっているか',
        self::PERSPECTIVE_FINDABILITY => '知りたい情報にたどり着けるか',
        self::PERSPECTIVE_USABILITY => '見やすく、使いやすいか',
    ];

    /**
     * ④観点の冒頭に必ず添える注記。Lighthouseのアクセシビリティ監査
     * (コントラスト比監査を含む)の範囲でしか自動判定していないことを
     * 明示し、配色・レイアウトの印象そのものは自動判定の対象外である旨を
     * 正直に伝える(2026-07-28のユーザー指摘への対応)。
     */
    public const USABILITY_SECTION_NOTE =
        '現時点では、読み上げソフトへの対応や文字と背景のコントラストなど、'.
        'アクセシビリティ監査の範囲で自動判定しています。配色やレイアウトの'.
        '見た目そのものの印象は自動判定の対象外です。デザインの良し悪しは、'.
        '商談の際に担当者と直接ご確認ください。';

    /**
     * ①観点(採用ページの法定記載事項チェック)は本フェーズでは未実装。
     * 画面・レポートに必ず添える注記。
     */
    public const COMPLETENESS_LEGAL_ITEMS_NOTE =
        '業務内容・給与・勤務時間といった募集要項の記載内容の確認は、今後追加予定です。'.
        '現時点では、採用ページ自体の有無と、その情報量のみを確認しています。';

    /**
     * @var array<string, array{label: string, perspective: string}>
     */
    private const CATALOG = [
        // ① 書くべきことが書けているか
        'recruit_link_present' => ['label' => '採用ページの案内', 'perspective' => self::PERSPECTIVE_COMPLETENESS],

        // ② メッセージの分かりやすさ
        'title_present' => ['label' => 'ページタイトルの設定', 'perspective' => self::PERSPECTIVE_CLARITY],
        'title_length_optimal' => ['label' => 'ページタイトルの長さ', 'perspective' => self::PERSPECTIVE_CLARITY],
        'meta_description_present' => ['label' => '検索結果に表示される紹介文', 'perspective' => self::PERSPECTIVE_CLARITY],
        'meta_description_length_optimal' => ['label' => '紹介文の長さ', 'perspective' => self::PERSPECTIVE_CLARITY],
        'h1_single' => ['label' => 'ページの主見出し', 'perspective' => self::PERSPECTIVE_CLARITY],
        'heading_structure_present' => ['label' => '見出しによる情報の整理', 'perspective' => self::PERSPECTIVE_CLARITY],
        'word_count_sufficient' => ['label' => 'ページ内の情報量', 'perspective' => self::PERSPECTIVE_CLARITY],

        // ③ 情報の取りやすさ・導線
        'internal_link_sufficient' => ['label' => 'サイト内の案内リンクの充実度', 'perspective' => self::PERSPECTIVE_FINDABILITY],
        'contact_cta_present' => ['label' => '問い合わせへの案内', 'perspective' => self::PERSPECTIVE_FINDABILITY],
        'tel_or_mailto_present' => ['label' => '電話・メールでの連絡手段', 'perspective' => self::PERSPECTIVE_FINDABILITY],
        'cta_count_sufficient' => ['label' => '応募・問い合わせへの入口の数', 'perspective' => self::PERSPECTIVE_FINDABILITY],
        'fixed_cta_present' => ['label' => '画面に常に表示される応募・問い合わせボタン', 'perspective' => self::PERSPECTIVE_FINDABILITY],
        'form_present' => ['label' => '応募・問い合わせの受け口', 'perspective' => self::PERSPECTIVE_FINDABILITY],
        'form_input_burden' => ['label' => '応募フォームの入力項目の多さ', 'perspective' => self::PERSPECTIVE_FINDABILITY],
        'faq_link_present' => ['label' => 'よくある質問の案内', 'perspective' => self::PERSPECTIVE_FINDABILITY],
        'help_center_link_present' => ['label' => 'サポート・ヘルプの案内', 'perspective' => self::PERSPECTIVE_FINDABILITY],
        'company_info_link_present' => ['label' => '会社情報の案内', 'perspective' => self::PERSPECTIVE_FINDABILITY],

        // ④ 見やすさ・使いやすさ
        'lighthouse_accessibility' => ['label' => '文字と背景の色のコントラスト、読み上げソフトへの対応', 'perspective' => self::PERSPECTIVE_USABILITY],
        'a11y_lang_present' => ['label' => '読み上げソフトへの対応(言語設定)', 'perspective' => self::PERSPECTIVE_USABILITY],
        'a11y_form_label_present' => ['label' => '読み上げソフトへの対応(入力項目の説明)', 'perspective' => self::PERSPECTIVE_USABILITY],
        'a11y_button_name_present' => ['label' => '読み上げソフトへの対応(ボタンの説明)', 'perspective' => self::PERSPECTIVE_USABILITY],
        'a11y_heading_order_ok' => ['label' => '読み上げソフトへの対応(見出しの順序)', 'perspective' => self::PERSPECTIVE_USABILITY],
        'viewport_present' => ['label' => 'スマートフォンでの表示対応', 'perspective' => self::PERSPECTIVE_USABILITY],
        'img_alt_coverage' => ['label' => '画像の説明文(読み上げ対応)', 'perspective' => self::PERSPECTIVE_USABILITY],
        'lighthouse_performance' => ['label' => 'ページの表示の速さ', 'perspective' => self::PERSPECTIVE_USABILITY],

        // Phase 3: 採用ページ自体を対象にした項目(①③④に相当する内容を、
        // 採用ページの実データから再度確認する)。
        'recruit_title_present' => ['label' => '採用ページのタイトル設定', 'perspective' => self::PERSPECTIVE_COMPLETENESS],
        'recruit_meta_description_present' => ['label' => '採用ページの紹介文', 'perspective' => self::PERSPECTIVE_COMPLETENESS],
        'recruit_h1_single' => ['label' => '採用ページの主見出し', 'perspective' => self::PERSPECTIVE_COMPLETENESS],
        'recruit_heading_structure_present' => ['label' => '採用ページの見出しによる情報の整理', 'perspective' => self::PERSPECTIVE_COMPLETENESS],
        'recruit_word_count_sufficient' => ['label' => '採用ページの情報量', 'perspective' => self::PERSPECTIVE_COMPLETENESS],
        'recruit_internal_link_sufficient' => ['label' => '採用ページ内の案内リンクの充実度', 'perspective' => self::PERSPECTIVE_FINDABILITY],
        'recruit_faq_link_present' => ['label' => '採用ページのよくある質問の案内', 'perspective' => self::PERSPECTIVE_FINDABILITY],
        'recruit_company_info_link_present' => ['label' => '採用ページの会社情報の案内', 'perspective' => self::PERSPECTIVE_FINDABILITY],
        'recruit_contact_cta_present' => ['label' => '採用ページの問い合わせへの案内', 'perspective' => self::PERSPECTIVE_FINDABILITY],
        'recruit_tel_or_mailto_present' => ['label' => '採用ページの電話・メールでの連絡手段', 'perspective' => self::PERSPECTIVE_FINDABILITY],
        'recruit_form_present' => ['label' => '採用ページの応募・問い合わせの受け口', 'perspective' => self::PERSPECTIVE_FINDABILITY],
        'recruit_viewport_present' => ['label' => '採用ページのスマートフォンでの表示対応', 'perspective' => self::PERSPECTIVE_USABILITY],
        'recruit_a11y_lang_present' => ['label' => '採用ページの読み上げソフトへの対応(言語設定)', 'perspective' => self::PERSPECTIVE_USABILITY],
        'recruit_a11y_form_label_present' => ['label' => '採用ページの読み上げソフトへの対応(入力項目の説明)', 'perspective' => self::PERSPECTIVE_USABILITY],
        'recruit_a11y_button_name_present' => ['label' => '採用ページの読み上げソフトへの対応(ボタンの説明)', 'perspective' => self::PERSPECTIVE_USABILITY],
        'recruit_a11y_heading_order_ok' => ['label' => '採用ページの読み上げソフトへの対応(見出しの順序)', 'perspective' => self::PERSPECTIVE_USABILITY],
        'recruit_lighthouse_accessibility' => ['label' => '採用ページの文字と背景の色のコントラスト、読み上げソフトへの対応', 'perspective' => self::PERSPECTIVE_USABILITY],
        'recruit_lighthouse_performance' => ['label' => '採用ページの表示の速さ', 'perspective' => self::PERSPECTIVE_USABILITY],
    ];

    public function perspectiveFor(string $metricKey): ?string
    {
        return self::CATALOG[$metricKey]['perspective'] ?? null;
    }

    public function label(string $metricKey): string
    {
        return self::CATALOG[$metricKey]['label'] ?? $metricKey;
    }

    public function isDisplayed(string $metricKey): bool
    {
        return isset(self::CATALOG[$metricKey]);
    }

    /**
     * @return list<string>
     */
    public function keysForPerspective(string $perspective): array
    {
        return array_values(array_map(
            fn (string $key) => $key,
            array_keys(array_filter(self::CATALOG, fn (array $entry) => $entry['perspective'] === $perspective)),
        ));
    }
}
