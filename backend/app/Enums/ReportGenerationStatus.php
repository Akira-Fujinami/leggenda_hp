<?php

namespace App\Enums;

enum ReportGenerationStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    // 2026-08-24追加: 自社サイトのブランド・ホイール分析がsuccessかつ
    // 1件以上マッチしていない(error/insufficient_input/matched=0)ため、
    // GenerateLeadReportJobを起動せず意図的に生成を見送った状態。
    // Failed(実際に生成処理が壊れた)とは区別する ―― 混同すると、意図した
    // 見送りが「本当の不具合」として社内ダッシュボードに埋もれてしまう
    // (LeadAnalysisController::maybeDispatchReportGeneration()参照)。
    case Skipped = 'skipped';
}
