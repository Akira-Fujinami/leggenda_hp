<?php

namespace App\Enums;

/**
 * 依頼CJ-1(2026-10-01): 「比較かどうか」をanalyses.source_analysis_idの
 * 有無で判定していたのを、明示的な列へ置き換える。source_analysis_idは
 * 「起点となった無料診断への実際のリンク」専用に戻す(無料診断を経由
 * しない比較(依頼CJ-2)ではこの列がnullのままになるため、有無では
 * 比較かどうかを判定できなくなる)。
 */
enum AnalysisKind: string
{
    case LeadDiagnosis = 'lead_diagnosis';
    case AdminComparison = 'admin_comparison';
}
