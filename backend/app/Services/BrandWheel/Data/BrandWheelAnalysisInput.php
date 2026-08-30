<?php

namespace App\Services\BrandWheel\Data;

/**
 * BrandWheelAnalysisProvider(Phase 4-Cで追加予定)へ渡す入力データ。
 *
 * 採用ページ/トップページの本文テキストと見出し構造、事業・サービスへの
 * リンクラベルのみを保持する。生HTML・スクリーンショット・Lighthouse/Semrush
 * 生データ・リードの個人情報/企業識別情報は一切含めない(型として持ち得ない
 * ことで、AIへ渡してよい情報の境界を強制する)。
 *
 * 依頼AR-6(2026-08-30): websiteAnalysisIdはtoArray()(=AIへのプロンプト・
 * input_hashの算出対象)から除外した。これはDB行の連番IDであり「入力の
 * 内容」ではないため、別々の診断(=別々のwebsite_analysis_id)は中身が
 * 一字一句同じでもinput_hashが一致しないという問題があった(依頼AQ-0で
 * 混乱の原因になった)。再利用判定(GenerateBrandWheelAnalysisJob)は
 * 元々`where('website_analysis_id', ...)`で明示的に絞っており、hashに
 * websiteAnalysisIdを含めるのは冗長なだけだった。プロパティ自体は
 * ログ・診断用に引き続き保持する(この変更で除外されるのはtoArray()の
 * 出力からのみ)。
 */
readonly class BrandWheelAnalysisInput
{
    /**
     * @param  list<array{level: int, text: string}>  $recruitPageHeadings
     * @param  list<array{level: int, text: string}>  $homepageHeadings
     * @param  list<string>  $businessLinkLabels
     * @param  list<string>  $allLinkLabels  ページ内の全リンクのラベル(header/nav/footerの
     *         スコープ制限なし)。BrandWheelAnalysisResponseParserのlabel_only_evidence
     *         判定専用(2026-08-05追加) ―― AIの判定材料ではなく、AIには渡さないため
     *         toArray()(=AIへ渡すデータ・input_hashの対象)には含めない。
     * @param  array{recruit_page: string, home_page: string}  $sourcePages  各ページの取得状態
     *         ('read'|'absent'|'unreadable')。#97のメール本文向けの診断情報であり、
     *         AIの判定材料ではないためtoArray()(=AIへ渡すデータ・input_hashの対象)には
     *         含めない。
     */
    public function __construct(
        public int $websiteAnalysisId,
        public ?string $recruitPageTitle,
        public string $recruitPageBodyText,
        public array $recruitPageHeadings,
        public ?string $homepageTitle,
        public string $homepageBodyText,
        public array $homepageHeadings,
        public array $businessLinkLabels,
        public bool $inputTruncated,
        public array $sourcePages,
        public array $allLinkLabels = [],
    ) {}

    /**
     * 依頼AR-6: websiteAnalysisId(診断ごとに一意なDB行ID、入力の内容では
     * ない)は含めない ―― AIへ渡すプロンプト、およびinput_hashはどちらも
     * この配列から算出されるため、ここに含めないことで両方から除外される。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'recruit_page_title' => $this->recruitPageTitle,
            'recruit_page_body_text' => $this->recruitPageBodyText,
            'recruit_page_headings' => $this->recruitPageHeadings,
            'homepage_title' => $this->homepageTitle,
            'homepage_body_text' => $this->homepageBodyText,
            'homepage_headings' => $this->homepageHeadings,
            'business_link_labels' => $this->businessLinkLabels,
            'input_truncated' => $this->inputTruncated,
        ];
    }
}
