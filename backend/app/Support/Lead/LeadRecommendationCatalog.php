<?php

namespace App\Support\Lead;

/**
 * リード(採用担当)向け「特に改善効果が見込まれる項目」専用の許可リスト・
 * 言い換え定義(2026-08-03)。
 *
 * 背景: RecommendationGenerator::generate()が作るRecommendation.title/
 * .descriptionは、社内の技術担当向けにmetric_definitions.name/
 * recommendation_templateをそのまま使う設計で、"Largest Contentful Paint"
 * のような英語指標名や「ファーストビュー」「導線」等の専門用語がそのまま
 * 採用担当の画面・Word/PDFレポートに出てしまっていた。
 *
 * LeadMetricCatalog(4観点)と同じ「マッピングが無いキーは自動的に非表示」
 * という設計を踏襲する。禁止リスト(除外キーワード)ではなく許可リストにする
 * 理由: 新しい指標が増えるたびに追従が必要な除外方式は壊れやすく、実際に
 * analytics_configured(アクセス解析タグ検出、採用担当の関心事ではない)が
 * 素通りする欠陥を生んでいた。許可リストなら「カタログに無い=出さない」が
 * 既定の安全側になる。
 *
 * リード向けに出す文言は必ずconfig('brand_wheel.forbidden_phrases')を含まない
 * こと(LeadRecommendationCatalogTestで全件検証)。社外に出る文章のため、
 * 「不足/欠如/劣る/弱い/低い」等の否定的評価語は使わない
 * ―― ブランド・ホイールのforbidden_phrasesと同じ考え方を適用する。
 *
 * Recommendation.title/.description(DB上の値、metric_definitions.name/
 * recommendation_templateそのまま)は書き換えない。社内向け画面・
 * 内部向けの分析には引き続きそちらを使う ―― ここは読み取り時にのみ
 * 適用される、リード向け表示専用の変換層である。
 */
class LeadRecommendationCatalog
{
    /**
     * Lighthouseの表示速度系サブ指標。採用担当にとっては「ページの表示が
     * 遅い」という1つの事実であり、どの下位指標(FCP/LCP/CLS/TBT)が引き金
     * だったかは伝える意味が無い。この4キーはすべて単一のグループキーへ
     * 集約し、複数該当しても1件にまとめる(RecommendationGenerator側の
     * category_key='performance'とは独立した、表示専用のグルーピング)。
     *
     * @var list<string>
     */
    private const PERFORMANCE_GROUP_METRIC_KEYS = ['fcp', 'lcp', 'cls', 'tbt'];

    /**
     * 表示速度グループの合成キー。実在するmetric_definitions.keyと衝突しない
     * ようプレフィックスを付ける。
     */
    public const PERFORMANCE_GROUP_KEY = '_performance_group';

    /**
     * 表示速度グループのtitleは、4観点で既に表示している
     * 'lighthouse_performance'のラベル(LeadMetricCatalog)をそのまま再利用する
     * ―― 画面の④観点「ページの表示の速さ」と、改善提案のタイトルが
     * 食い違わないようにするため。
     */
    private const PERFORMANCE_GROUP_TITLE_SOURCE_KEY = 'lighthouse_performance';

    /**
     * 許可リスト本体。ここに無いkeyの改善提案はリード向けに一切出さない。
     * titleはLeadMetricCatalog::label()を再利用する(4観点の項目名と改善提案の
     * タイトルを同じ言葉にするため、ここでは独自のtitleテーブルを持たない)。
     * descriptionのみ、改善提案としての言い回し(「何が起きているか」+
     * 「誰に相談すれば直るか」)をここで独自に定義する。
     *
     * @var array<string, string>
     */
    private const DESCRIPTIONS = [
        'title_present' => 'ページタイトルが設定されていません。サイトの制作・運用を担当されている方にご相談ください。',
        'title_length_optimal' => 'ページタイトルの文字数を10〜65文字程度に調整すると、検索結果や候補者の一覧で内容が伝わりやすくなります。サイトの制作・運用を担当されている方にご相談ください。',
        'meta_description_present' => '検索結果に表示される紹介文が設定されていません。サイトの制作・運用を担当されている方にご相談ください。',
        'meta_description_length_optimal' => '検索結果に表示される紹介文の文字数を50〜160文字程度に調整すると、内容が伝わりやすくなります。サイトの制作・運用を担当されている方にご相談ください。',
        'h1_single' => 'ページの主な見出しを1つ、分かりやすく設定すると、何のページかが伝わりやすくなります。サイトの制作・運用を担当されている方にご相談ください。',
        'heading_structure_present' => '見出しを使って内容を整理すると、読みやすさが上がります。サイトの制作・運用を担当されている方にご相談ください。',
        'word_count_sufficient' => 'ページ本文の情報量を増やすと、会社や仕事内容がより伝わりやすくなります(目安300文字以上)。サイトの制作・運用を担当されている方にご相談ください。',
        'internal_link_sufficient' => '関連する他のページへの案内(リンク)を増やすと、知りたい情報にたどり着きやすくなります。サイトの制作・運用を担当されている方にご相談ください。',
        'contact_cta_present' => '問い合わせ先への案内を分かりやすく設置すると、応募・問い合わせにつながりやすくなります。サイトの制作・運用を担当されている方にご相談ください。',
        'tel_or_mailto_present' => '電話番号やメールアドレスへのリンクを設置すると、直接の連絡がしやすくなります。サイトの制作・運用を担当されている方にご相談ください。',
        'cta_count_sufficient' => '応募・問い合わせにつながる案内(ボタンやリンク)を増やすと、行動につながりやすくなります。サイトの制作・運用を担当されている方にご相談ください。',
        'fixed_cta_present' => 'スクロールしても常に見える位置に応募・問い合わせへの案内を置くと、見つけてもらいやすくなります。サイトの制作・運用を担当されている方にご相談ください。',
        'form_present' => '応募・問い合わせフォームを設置すると、候補者が直接連絡しやすくなります。サイトの制作・運用を担当されている方にご相談ください。',
        'form_input_burden' => '応募・問い合わせフォームの入力項目を減らすと、応募のハードルが下がります。サイトの制作・運用を担当されている方にご相談ください。',
        'a11y_form_label_present' => 'フォームの入力項目に、それぞれ何を入力するかの説明を付けると、読み上げソフトを使う方にも伝わりやすくなります。サイトの制作・運用を担当されている方にご相談ください。',
        'a11y_button_name_present' => 'ボタンに何をするボタンかが分かる文言を設定すると、読み上げソフトを使う方にも伝わりやすくなります。サイトの制作・運用を担当されている方にご相談ください。',
        'a11y_heading_order_ok' => '見出しの階層(大見出し・中見出し等)の順序を整えると、読み上げソフトを使う方にも内容が伝わりやすくなります。サイトの制作・運用を担当されている方にご相談ください。',
        'viewport_present' => 'スマートフォンで見たときに正しく表示されるよう設定すると、多くの候補者が読みやすくなります。サイトの制作・運用を担当されている方にご相談ください。',
        'img_alt_coverage' => '重要な画像に、読み上げソフト向けの説明文を設定すると、より多くの方に内容が伝わります。サイトの制作・運用を担当されている方にご相談ください。',
        self::PERFORMANCE_GROUP_KEY => 'ページの表示速度を改善すると、閲覧中に離脱されにくくなります。サイトの制作を担当されている制作会社・担当者にご相談ください。',
    ];

    public function __construct(
        private readonly LeadMetricCatalog $metricCatalog,
    ) {}

    /**
     * $metricKeyがリード向け表示の対象であれば、代表キー(表示速度系は
     * グループキーへ集約)を返す。対象外ならnull。
     */
    public function groupKeyFor(string $metricKey): ?string
    {
        if (in_array($metricKey, self::PERFORMANCE_GROUP_METRIC_KEYS, true)) {
            return self::PERFORMANCE_GROUP_KEY;
        }

        return array_key_exists($metricKey, self::DESCRIPTIONS) ? $metricKey : null;
    }

    public function isAllowed(string $metricKey): bool
    {
        return $this->groupKeyFor($metricKey) !== null;
    }

    public function title(string $groupKey): string
    {
        if ($groupKey === self::PERFORMANCE_GROUP_KEY) {
            return $this->metricCatalog->label(self::PERFORMANCE_GROUP_TITLE_SOURCE_KEY);
        }

        return $this->metricCatalog->label($groupKey);
    }

    public function description(string $groupKey): string
    {
        return self::DESCRIPTIONS[$groupKey] ?? '';
    }

    /**
     * @return list<string>
     */
    public function allowedGroupKeys(): array
    {
        return array_keys(self::DESCRIPTIONS);
    }
}
