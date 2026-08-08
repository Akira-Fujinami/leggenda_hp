<?php

namespace App\Services\BrandWheel\Data;

/**
 * BrandWheelAnalysisProvider(Phase 4-Cで追加予定)へ渡す入力データ。
 *
 * 採用ページ/トップページの本文テキストと見出し構造、事業・サービスへの
 * リンクラベルのみを保持する。生HTML・スクリーンショット・Lighthouse/Semrush
 * 生データ・リードの個人情報/企業識別情報は一切含めない(型として持ち得ない
 * ことで、AIへ渡してよい情報の境界を強制する)。
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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'website_analysis_id' => $this->websiteAnalysisId,
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
