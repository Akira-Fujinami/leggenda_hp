<?php

namespace App\Jobs\Report;

use App\Enums\ReportFormat;
use App\Enums\ReportGenerationStatus;
use App\Models\Analysis;
use App\Models\Report;
use App\Services\Lead\LeadDiagnosisCompletedNotifier;
use App\Services\Report\PdfReportGenerator;
use App\Services\Report\ReportViewModelBuilder;
use App\Services\Report\WordReportGenerator;
use App\Support\Report\ReportViewModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * リード向け簡易診断のWord/PDFレポートを生成する。LeadAnalysisController側の
 * 遅延ディスパッチ(結果が終端状態になった最初のポーリングで1回だけ発火)から
 * 起動され、既存のFinalizeWebsiteAnalysisJob/FinalizeAnalysisJob等の共有
 * パイプラインには一切触れない、完全に独立したJob。
 *
 * (analysis_id, format)ごとに1行のReportを持つ(unique制約)。format単位で
 * 冪等 ―― 既にcompletedな形式は再実行しない。片方の形式が失敗しても
 * もう片方の生成は試みる。
 */
class GenerateLeadReportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 120;

    public $uniqueFor = 600;

    public function __construct(public readonly int $analysisId) {}

    public function uniqueId(): string
    {
        return "lead-report:{$this->analysisId}";
    }

    public function handle(
        ReportViewModelBuilder $viewModelBuilder,
        WordReportGenerator $wordGenerator,
        PdfReportGenerator $pdfGenerator,
        LeadDiagnosisCompletedNotifier $diagnosisCompletedNotifier,
    ): void {
        $analysis = Analysis::with('project.leadSession')->find($this->analysisId);

        if ($analysis === null) {
            return;
        }

        $leadSession = $analysis->project?->leadSession;

        if ($leadSession === null) {
            // 内部向け分析(lead_session_idを持たないProject)からは
            // このJobは絶対にdispatchされない想定だが、二重の安全弁として
            // 何もしない(社内データにレポートを生成しない)。
            return;
        }

        $viewModel = $viewModelBuilder->build($analysis, $leadSession);

        // 依頼Y-1(2026-08-26): dompdf/PhpWordが実際にどれだけメモリを
        // 使っているかを継続的に観測する(memory_limitの値を勘で決めないため。
        // 5件同時実行時にPDF生成ジョブが同時に立ち上がりコンテナがOOM
        // Killされた事故の再発防止)。memory_reset_peak_usage()(PHP 8.2+)で
        // 各フォーマットの生成直前にピークをリセットすることで、直前の
        // ViewModel組み立てやもう一方のフォーマットの分を含まない、
        // フォーマット単体のピークを記録できる。
        memory_reset_peak_usage();
        $memoryBeforeDocx = memory_get_usage(true);
        $docxSucceeded = $this->generateFormat(ReportFormat::Docx, fn () => $wordGenerator->generate($viewModel), $viewModel);
        $this->logPeakMemoryUsage(ReportFormat::Docx, $memoryBeforeDocx);

        memory_reset_peak_usage();
        $memoryBeforePdf = memory_get_usage(true);
        $pdfSucceeded = $this->generateFormat(ReportFormat::Pdf, fn () => $pdfGenerator->generate($viewModel), $viewModel);
        $this->logPeakMemoryUsage(ReportFormat::Pdf, $memoryBeforePdf);

        if (! $docxSucceeded || ! $pdfSucceeded) {
            throw new RuntimeException("レポート生成に失敗した形式があります(analysis_id={$this->analysisId})");
        }

        // 依頼AS-1/AS-2(2026-09-03): 「診断が完了しました」メール(相談
        // リクエストの有無にかかわらず送る)は、Word・PDF両方が実際に
        // 完成したこの時点で送る ―― 判定完了時点(BrandWheelCompletionNotifier、
        // 既存・無改修)では、この後に改善提案の生成待ち(依頼AM-1、最大
        // 約75秒)とレポート生成そのものが残っており、レポートが実際には
        // まだ存在しない状態でメールが飛んでしまう問題があった(依頼AS-1で
        // 確認)。例外はここで握りつぶす(ログにのみ残す) ―― メール送信の
        // 失敗が、既に完了しているレポート生成の成否に影響してはならない。
        try {
            $diagnosisCompletedNotifier->notifyIfReady($analysis);
        } catch (Throwable $e) {
            report($e);
            Log::warning('Lead diagnosis completed notification failed unexpectedly', [
                'analysis_id' => $this->analysisId,
            ]);
        }
    }

    /**
     * 依頼Y-1: 本文・プロンプト・APIキー・顧客情報は一切含めない(数値のみ)。
     * このログは恒久的に残す(依頼者指定 ―― 5件同時実行後も実際の消費量を
     * 継続的に見たいため)。
     */
    private function logPeakMemoryUsage(ReportFormat $format, int $memoryBeforeBytes): void
    {
        Log::info('Lead report generation peak memory usage', [
            'analysis_id' => $this->analysisId,
            'format' => $format->value,
            'memory_before_bytes' => $memoryBeforeBytes,
            'memory_peak_bytes' => memory_get_peak_usage(true),
        ]);
    }

    /**
     * @param  callable(): string  $generate
     */
    private function generateFormat(ReportFormat $format, callable $generate, ReportViewModel $viewModel): bool
    {
        $report = Report::query()->firstOrCreate(
            ['analysis_id' => $this->analysisId, 'format' => $format->value],
            ['storage_path' => '', 'status' => ReportGenerationStatus::Pending->value],
        );

        if ($report->status === ReportGenerationStatus::Completed) {
            return true;
        }

        try {
            $bytes = $generate();
            $storagePath = "reports/{$this->analysisId}/report.{$format->fileExtension()}";
            Storage::disk('analysis')->put($storagePath, $bytes);

            $report->update([
                'storage_path' => $storagePath,
                'status' => ReportGenerationStatus::Completed->value,
                'generated_at' => now(),
                'error_message' => null,
            ]);

            return true;
        } catch (Throwable $e) {
            $report->update([
                'status' => ReportGenerationStatus::Failed->value,
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Lead report generation failed', [
                'analysis_id' => $this->analysisId,
                'format' => $format->value,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
