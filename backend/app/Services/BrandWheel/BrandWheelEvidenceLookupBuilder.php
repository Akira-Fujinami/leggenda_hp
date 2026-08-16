<?php

namespace App\Services\BrandWheel;

use App\Models\BrandWheelAnalysisResult;

/**
 * 生のBrandWheelAnalysisResult.axesから(axis_key => (sub_element_key =>
 * evidence))のルックアップを組み立てる。ReportViewModelBuilder(改善提案
 * ページの証拠カード用、2026-08-04〜)とGenerateBrandWheelImprovementSuggestion
 * Job(改善提案AIの入力用、2026-08-17〜)で共有する ―― 同じロジックを2箇所に
 * 持たない。
 */
class BrandWheelEvidenceLookupBuilder
{
    /**
     * @return array<string, array<string, string>>
     */
    public function build(?BrandWheelAnalysisResult $record): array
    {
        if ($record === null) {
            return [];
        }

        $evidenceByAxisAndSubKey = [];
        foreach ((array) ($record->axes ?? []) as $axis) {
            foreach ((array) ($axis['matched_sub_elements'] ?? []) as $sub) {
                $evidenceByAxisAndSubKey[$axis['axis_key']][$sub['key']] = (string) ($sub['evidence'] ?? '');
            }
        }

        return $evidenceByAxisAndSubKey;
    }
}
