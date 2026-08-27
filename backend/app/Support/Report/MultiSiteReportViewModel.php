<?php

namespace App\Support\Report;

/**
 * 依頼AC(2026-08-27): 管理者向け多社比較レポート(自社1×競合N社、N=3〜5、
 * PDFのみ)専用のViewModel。既存のReportViewModel(リード向け、Word/PDF共通)
 * とは意図的に完全に別クラスにする ―― 既存のReportViewModelBuilder/
 * PdfReportGenerator/WordReportGeneratorは無改修のまま(依頼AC禁止事項)、
 * このレポートの生成経路をそこから完全に切り離すため。
 *
 * リード向けレポートと異なり、Word版は作らない(依頼者指定、管理者専用の
 * 内部資料のためPDFのみで足りる)。
 */
readonly class MultiSiteReportViewModel
{
    /**
     * @param  list<array{name: string, url: string}>  $competitors  display_order順(自社を含まない、競合のみ)
     * @param  list<array{axis_name: string, sub_name: string, definition: string, recommendation: string, competitor_matched_count: int, representative_company_name: ?string, quote: ?string, quote_translation: ?string}>  $missingFromSelf  依頼AC-1の①(自社に足りない項目)、件数降順。representative_company_name/quoteは該当する競合が無い場合null(理論上は必ず1社以上該当するため実際には発生しない)。
     * @param  list<array{axis_name: string, sub_name: string, definition: string, competitor_matched_count: int}>  $selfStrengths  依頼AC-1の②(自社の強み)、件数降順。競合引用は付けない(依頼者承認の範囲は①のみ)。
     * @param  list<array{axis_name: string, group: string, sub_name: string, self_matched: bool, competitor_matched: list<bool>}>  $comparisonTable  24項目×(自社+競合N社)、config順。competitor_matchedは$competitorsと同じ添字(display_order順)。
     * @param  list<array{axis_name: string, items: list<array{sub_name: string, evidence: string, evidence_translation: ?string}>}>  $selfEvidenceByAxis  自社の「○と判定した根拠」(依頼R方針を踏襲、競合の引用は含まない)
     */
    public function __construct(
        public string $selfCompanyDisplayName,
        public string $generatedAtLabel,
        public string $selfWebsiteUrl,
        public array $competitors,
        public int $competitorCount,
        public int $majorityThreshold,
        public bool $selfReadable,
        public int $selfTotalMatched,
        public int $selfTotalMax,
        public ?string $brandWheelRadarPngCombined,
        public array $missingFromSelf,
        public array $selfStrengths,
        public array $comparisonTable,
        public array $selfEvidenceByAxis,
        public bool $hasQuoteTranslations,
    ) {}
}
