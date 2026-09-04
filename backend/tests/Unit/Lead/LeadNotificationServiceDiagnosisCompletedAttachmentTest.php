<?php

namespace Tests\Unit\Lead;

use App\Enums\ReportFormat;
use App\Enums\ReportGenerationStatus;
use App\Mail\BrandWheelLeadDiagnosisCompletedMail;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\Report;
use App\Services\Lead\LeadNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼AW-1(2026-09-04): 「診断が完了しました」メールへのPDF添付。
 * LeadNotificationServiceBrandWheelTest.php(依頼AS-2、送信可否判定)とは
 * 別の観点(添付の有無)のみを扱う専用ファイル。
 *
 * BrandWheelAnalysisResultは(既存のLeadNotificationServiceBrandWheelTest.php
 * と同じく)DBへ保存せず`new`で組み立てる ―― website_analysis_id等の
 * 無関係なFK制約に付き合う必要が無くなる。一方Report行はanalyses.idへの
 * FK制約があるため、こちらは実在するAnalysis::factory()->create()の
 * idを使う。
 */
class LeadNotificationServiceDiagnosisCompletedAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeSendableResult(int $analysisId): BrandWheelAnalysisResult
    {
        return new BrandWheelAnalysisResult([
            'analysis_id' => $analysisId,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'state' => 'read', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => '地域社会に貢献するという理念'],
                ]],
            ],
            'axis_state_counts' => ['read' => 1, 'partial' => 0, 'unread' => 5],
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ]);
    }

    public function test_attaches_the_completed_pdf_report_with_the_lead_facing_filename(): void
    {
        Storage::fake('analysis');
        Mail::fake();

        $analysisId = Analysis::factory()->create()->id;
        $result = $this->makeSendableResult($analysisId);
        Storage::disk('analysis')->put("reports/{$analysisId}/report.pdf", '%PDF-1.4 fake bytes');
        Report::factory()->create([
            'analysis_id' => $analysisId,
            'format' => ReportFormat::Pdf,
            'storage_path' => "reports/{$analysisId}/report.pdf",
            'status' => ReportGenerationStatus::Completed,
            'generated_at' => now(),
        ]);

        $sent = app(LeadNotificationService::class)->notifyDiagnosisCompletedToLead(
            $result, 'lead@example.com', 'https://example.com',
        );

        $this->assertTrue($sent);
        Mail::assertSent(BrandWheelLeadDiagnosisCompletedMail::class, function (BrandWheelLeadDiagnosisCompletedMail $mail) {
            return $mail->pdfAttachment !== null
                && $mail->pdfAttachment->as === '診断レポート.pdf'
                && $mail->pdfAttachment->mime === 'application/pdf';
        });
    }

    public function test_does_not_attach_a_docx_report(): void
    {
        Storage::fake('analysis');
        Mail::fake();

        $analysisId = Analysis::factory()->create()->id;
        $result = $this->makeSendableResult($analysisId);
        Storage::disk('analysis')->put("reports/{$analysisId}/report.docx", 'fake docx bytes');
        Report::factory()->create([
            'analysis_id' => $analysisId,
            'format' => ReportFormat::Docx,
            'storage_path' => "reports/{$analysisId}/report.docx",
            'status' => ReportGenerationStatus::Completed,
            'generated_at' => now(),
        ]);

        Log::spy();

        $sent = app(LeadNotificationService::class)->notifyDiagnosisCompletedToLead(
            $result, 'lead@example.com', 'https://example.com',
        );

        $this->assertTrue($sent);
        Mail::assertSent(BrandWheelLeadDiagnosisCompletedMail::class, fn (BrandWheelLeadDiagnosisCompletedMail $mail) => $mail->pdfAttachment === null);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $message === 'Lead diagnosis completed notification: pdf attachment unavailable'
                && $context['analysis_id'] === $analysisId
                && $context['reason'] === 'no_report_row')
            ->once();
    }

    public function test_sends_without_attachment_and_logs_a_warning_when_no_report_row_exists(): void
    {
        Storage::fake('analysis');
        Mail::fake();
        Log::spy();

        $analysisId = Analysis::factory()->create()->id;
        $result = $this->makeSendableResult($analysisId);

        $sent = app(LeadNotificationService::class)->notifyDiagnosisCompletedToLead(
            $result, 'lead@example.com', 'https://example.com',
        );

        $this->assertTrue($sent);
        Mail::assertSent(BrandWheelLeadDiagnosisCompletedMail::class, fn (BrandWheelLeadDiagnosisCompletedMail $mail) => $mail->pdfAttachment === null);
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($analysisId) {
                $encoded = json_encode($context);

                return $message === 'Lead diagnosis completed notification: pdf attachment unavailable'
                    && $context['analysis_id'] === $analysisId
                    && $context['reason'] === 'no_report_row'
                    && array_keys($context) === ['analysis_id', 'reason']
                    && ! str_contains($encoded, 'lead@example.com');
            })
            ->once();
    }

    public function test_sends_without_attachment_and_logs_a_warning_when_the_report_is_not_completed(): void
    {
        Storage::fake('analysis');
        Mail::fake();
        Log::spy();

        $analysisId = Analysis::factory()->create()->id;
        $result = $this->makeSendableResult($analysisId);
        Report::factory()->create([
            'analysis_id' => $analysisId,
            'format' => ReportFormat::Pdf,
            'storage_path' => "reports/{$analysisId}/report.pdf",
            'status' => ReportGenerationStatus::Failed,
        ]);

        $sent = app(LeadNotificationService::class)->notifyDiagnosisCompletedToLead(
            $result, 'lead@example.com', 'https://example.com',
        );

        $this->assertTrue($sent);
        Mail::assertSent(BrandWheelLeadDiagnosisCompletedMail::class, fn (BrandWheelLeadDiagnosisCompletedMail $mail) => $mail->pdfAttachment === null);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $message === 'Lead diagnosis completed notification: pdf attachment unavailable'
                && $context['analysis_id'] === $analysisId
                && $context['reason'] === 'report_not_completed')
            ->once();
    }

    public function test_sends_without_attachment_and_logs_a_warning_when_the_file_is_missing_on_disk(): void
    {
        Storage::fake('analysis');
        Mail::fake();
        Log::spy();

        $analysisId = Analysis::factory()->create()->id;
        $result = $this->makeSendableResult($analysisId);
        // Storageには実際には置かない ―― DBの行だけが存在するズレを再現する。
        Report::factory()->create([
            'analysis_id' => $analysisId,
            'format' => ReportFormat::Pdf,
            'storage_path' => "reports/{$analysisId}/report.pdf",
            'status' => ReportGenerationStatus::Completed,
            'generated_at' => now(),
        ]);

        $sent = app(LeadNotificationService::class)->notifyDiagnosisCompletedToLead(
            $result, 'lead@example.com', 'https://example.com',
        );

        $this->assertTrue($sent);
        Mail::assertSent(BrandWheelLeadDiagnosisCompletedMail::class, fn (BrandWheelLeadDiagnosisCompletedMail $mail) => $mail->pdfAttachment === null);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $message === 'Lead diagnosis completed notification: pdf attachment unavailable'
                && $context['analysis_id'] === $analysisId
                && $context['reason'] === 'file_missing_on_disk')
            ->once();
    }
}
