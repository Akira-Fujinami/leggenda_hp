<?php

namespace App\Jobs\Report;

use App\Enums\ReportFormat;
use App\Enums\ReportGenerationStatus;
use App\Models\Analysis;
use App\Models\Report;
use App\Services\Report\AdminComparisonPdfGenerator;
use App\Services\Report\MultiSiteReportViewModelBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 依頼AC(2026-08-27): 管理者向け多社比較レポート(PDFのみ)を生成する。
 * 既存のGenerateLeadReportJob(リード向け、Word+PDF、LeadSession必須)とは
 * 完全に独立したJob ―― こちらはFinalizeAnalysisJobから
 * $analysis->source_analysis_idが非nullの場合にのみdispatchされる
 * (App\Jobs\Analysis\FinalizeAnalysisJob参照)。
 *
 * (analysis_id, format='pdf')ごとに1行のReportを持つ点はGenerateLeadReportJobと
 * 同じ仕組み(既存のReportモデル・enumをそのまま再利用、新しいテーブル・
 * enumケースは追加しない) ―― 比較用AnalysisはリードのGenerateLeadReportJobの
 * 対象に一切ならない(LeadReportDispatchServiceがleadSession===nullで
 * 早期returnするため)ため、同一analysis_idでの形式衝突は起こらない。
 */
class GenerateAdminComparisonReportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 120;

    public $uniqueFor = 600;

    public function __construct(public readonly int $analysisId) {}

    public function uniqueId(): string
    {
        return "admin-comparison-report:{$this->analysisId}";
    }

    public function handle(
        MultiSiteReportViewModelBuilder $viewModelBuilder,
        AdminComparisonPdfGenerator $pdfGenerator,
    ): void {
        $analysis = Analysis::with('project.leadCompany')->find($this->analysisId);

        if ($analysis === null) {
            return;
        }

        // 比較Analysis(source_analysis_idが非null)以外からは、このJobは
        // 絶対にdispatchされない想定だが、二重の安全弁として何もしない
        // (通常のリード診断に多社比較レポートを生成しない)。
        if ($analysis->source_analysis_id === null) {
            return;
        }

        $report = Report::query()->firstOrCreate(
            ['analysis_id' => $this->analysisId, 'format' => ReportFormat::Pdf->value],
            ['storage_path' => '', 'status' => ReportGenerationStatus::Pending->value],
        );

        if ($report->status === ReportGenerationStatus::Completed) {
            return;
        }

        $viewModel = $viewModelBuilder->build($analysis);

        // 依頼AC-5/依頼Y-1と同じ形式・同じ用途(memory_limitの値を実測で
        // 決めるための恒久ログ)。本文・引用・URLは一切含めない(数値のみ)。
        memory_reset_peak_usage();
        $memoryBeforeBytes = memory_get_usage(true);

        try {
            $bytes = $pdfGenerator->generate($viewModel);
            $storagePath = "reports/{$this->analysisId}/admin-comparison-report.pdf";
            Storage::disk('analysis')->put($storagePath, $bytes);

            $report->update([
                'storage_path' => $storagePath,
                'status' => ReportGenerationStatus::Completed->value,
                'generated_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            $report->update([
                'status' => ReportGenerationStatus::Failed->value,
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Admin comparison report generation failed', [
                'analysis_id' => $this->analysisId,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
            ]);
        } finally {
            Log::info('Admin comparison report generation peak memory usage', [
                'analysis_id' => $this->analysisId,
                'format' => ReportFormat::Pdf->value,
                'memory_before_bytes' => $memoryBeforeBytes,
                'memory_peak_bytes' => memory_get_peak_usage(true),
            ]);
        }
    }
}
