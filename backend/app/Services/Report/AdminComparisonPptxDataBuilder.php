<?php

namespace App\Services\Report;

use App\Support\Report\MultiSiteReportViewModel;

/**
 * 依頼BG: 既存のMultiSiteReportViewModel(多社比較PDFと同じ唯一の情報源、
 * MultiSiteReportViewModelBuilder参照)を、AdminComparisonPptxGenerator::
 * generate()が期待するデータ形へ変換するだけの薄い変換層。判定・集計
 * ロジックは一切持たない ―― PDF/PPTXが異なる数値を見せることが無いよう、
 * 既存のViewModelが持つ値をそのまま渡す。
 */
class AdminComparisonPptxDataBuilder
{
    /**
     * @return array{
     *     self_company_name: string,
     *     companies: list<array{name: string, matched: int, total: int, is_self: bool}>,
     *     competitor_count: int,
     *     rows: list<array{sub_name: string, axis_name: string, matched_count: int, quote: ?string}>,
     *     source_note: string,
     *     page_number: ?string,
     * }
     */
    public function build(MultiSiteReportViewModel $viewModel): array
    {
        $totalItems = count($viewModel->comparisonTable);

        $companies = [];
        $companies[] = [
            'name' => $viewModel->selfCompanyDisplayName,
            'matched' => $viewModel->selfTotalMatched,
            'total' => $viewModel->selfTotalMax,
            'is_self' => true,
        ];

        foreach ($viewModel->competitors as $index => $competitor) {
            $matched = 0;
            foreach ($viewModel->comparisonTable as $item) {
                if ($item['competitor_matched'][$index] ?? false) {
                    $matched++;
                }
            }

            $companies[] = [
                'name' => $competitor['name'],
                'matched' => $matched,
                'total' => $totalItems,
                'is_self' => false,
            ];
        }

        $rows = array_map(fn (array $item) => [
            'sub_name' => $item['sub_name'],
            'axis_name' => $item['axis_name'],
            'matched_count' => $item['competitor_matched_count'],
            'quote' => $item['quote'],
        ], $viewModel->missingFromSelf);

        return [
            'self_company_name' => $viewModel->selfCompanyDisplayName,
            'companies' => $companies,
            'competitor_count' => $viewModel->competitorCount,
            'rows' => $rows,
            'source_note' => "Leggenda 採用ブランド・ホイール診断({$viewModel->generatedAtLabel}時点)",
            'page_number' => null,
        ];
    }
}
