<?php

namespace App\Jobs\Report;

use App\Enums\ReportFormat;
use App\Enums\ReportGenerationStatus;
use App\Models\Analysis;
use App\Models\Report;
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

        $docxSucceeded = $this->generateFormat(ReportFormat::Docx, fn () => $wordGenerator->generate($viewModel), $viewModel);
        $pdfSucceeded = $this->generateFormat(ReportFormat::Pdf, fn () => $pdfGenerator->generate($viewModel), $viewModel);

        if (! $docxSucceeded || ! $pdfSucceeded) {
            throw new RuntimeException("レポート生成に失敗した形式があります(analysis_id={$this->analysisId})");
        }
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
