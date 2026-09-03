<?php

namespace Tests\Unit\Jobs\Report;

use App\Enums\ReportGenerationStatus;
use App\Jobs\Report\GenerateLeadReportJob;
use App\Mail\BrandWheelLeadDiagnosisCompletedMail;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\LeadSession;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Lead\LeadDiagnosisCompletedNotifier;
use App\Services\Report\PdfReportGenerator;
use App\Services\Report\ReportViewModelBuilder;
use App\Services\Report\WordReportGenerator;
use Database\Seeders\CategoryDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateLeadReportJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategoryDefinitionSeeder::class);
        Storage::fake('analysis');
    }

    private function makeLeadAnalysis(): Analysis
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id]);
        $website = Website::factory()->create(['project_id' => $project->id, 'is_primary' => true]);
        WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        return $analysis;
    }

    /**
     * 依頼AS-2(2026-09-03): BrandWheelLeadEmailContentBuilder::canSend()が
     * trueになる(=送信可能な)自社サイトのBrandWheelAnalysisResultを作る。
     *
     * @param  array<string, mixed>  $overrides
     */
    private function attachSendableBrandWheelResult(Analysis $analysis, array $overrides = []): BrandWheelAnalysisResult
    {
        $selfWebsiteAnalysis = WebsiteAnalysis::query()
            ->where('analysis_id', $analysis->id)
            ->whereHas('website', fn ($q) => $q->where('is_primary', true))
            ->firstOrFail();

        return BrandWheelAnalysisResult::factory()->create(array_merge([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWebsiteAnalysis->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'state' => 'read', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => '地域社会に貢献するという理念を掲げています。'],
                ]],
            ],
            'axis_state_counts' => ['read' => 1, 'partial' => 0, 'unread' => 5],
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ], $overrides));
    }

    public function test_it_generates_both_formats_and_stores_them(): void
    {
        $analysis = $this->makeLeadAnalysis();

        (new GenerateLeadReportJob($analysis->id))->handle(
            app(ReportViewModelBuilder::class),
            app(WordReportGenerator::class),
            app(PdfReportGenerator::class),
            app(LeadDiagnosisCompletedNotifier::class),
        );

        $docxReport = Report::where('analysis_id', $analysis->id)->where('format', 'docx')->first();
        $pdfReport = Report::where('analysis_id', $analysis->id)->where('format', 'pdf')->first();

        $this->assertNotNull($docxReport);
        $this->assertNotNull($pdfReport);
        $this->assertSame(ReportGenerationStatus::Completed, $docxReport->status);
        $this->assertSame(ReportGenerationStatus::Completed, $pdfReport->status);
        Storage::disk('analysis')->assertExists($docxReport->storage_path);
        Storage::disk('analysis')->assertExists($pdfReport->storage_path);
    }

    public function test_it_is_idempotent_and_does_not_regenerate_an_already_completed_format(): void
    {
        $analysis = $this->makeLeadAnalysis();
        Storage::disk('analysis')->put("reports/{$analysis->id}/report.docx", 'existing-bytes');
        Report::factory()->create([
            'analysis_id' => $analysis->id,
            'format' => 'docx',
            'storage_path' => "reports/{$analysis->id}/report.docx",
            'status' => ReportGenerationStatus::Completed,
            'generated_at' => now(),
        ]);

        (new GenerateLeadReportJob($analysis->id))->handle(
            app(ReportViewModelBuilder::class),
            app(WordReportGenerator::class),
            app(PdfReportGenerator::class),
            app(LeadDiagnosisCompletedNotifier::class),
        );

        $this->assertSame('existing-bytes', Storage::disk('analysis')->get("reports/{$analysis->id}/report.docx"));
        $this->assertSame(1, Report::where('analysis_id', $analysis->id)->where('format', 'docx')->count());
    }

    public function test_it_does_nothing_for_an_analysis_without_a_lead_session(): void
    {
        $project = Project::factory()->create();
        $analysis = Analysis::factory()->create(['project_id' => $project->id]);

        (new GenerateLeadReportJob($analysis->id))->handle(
            app(ReportViewModelBuilder::class),
            app(WordReportGenerator::class),
            app(PdfReportGenerator::class),
            app(LeadDiagnosisCompletedNotifier::class),
        );

        $this->assertSame(0, Report::where('analysis_id', $analysis->id)->count());
    }

    public function test_it_does_nothing_for_a_deleted_analysis(): void
    {
        (new GenerateLeadReportJob(999999))->handle(
            app(ReportViewModelBuilder::class),
            app(WordReportGenerator::class),
            app(PdfReportGenerator::class),
            app(LeadDiagnosisCompletedNotifier::class),
        );

        $this->assertSame(0, Report::count());
    }

    /**
     * 依頼Y-1(2026-08-26): dompdf/PhpWordの実際のピークメモリを、PDF/Wordを
     * 分けて構造化ログに残す。本文・プロンプト・APIキー・顧客情報を含まない
     * こと(数値フィールドのみであること)も確認する。
     */
    public function test_it_logs_peak_memory_usage_separately_for_docx_and_pdf(): void
    {
        $analysis = $this->makeLeadAnalysis();

        Log::spy();

        (new GenerateLeadReportJob($analysis->id))->handle(
            app(ReportViewModelBuilder::class),
            app(WordReportGenerator::class),
            app(PdfReportGenerator::class),
            app(LeadDiagnosisCompletedNotifier::class),
        );

        foreach (['docx', 'pdf'] as $format) {
            Log::shouldHaveReceived('info')
                ->withArgs(function (string $message, array $context) use ($analysis, $format) {
                    return $message === 'Lead report generation peak memory usage'
                        && $context['analysis_id'] === $analysis->id
                        && $context['format'] === $format
                        && is_int($context['memory_before_bytes'])
                        && is_int($context['memory_peak_bytes'])
                        && $context['memory_peak_bytes'] > 0
                        && array_keys($context) === ['analysis_id', 'format', 'memory_before_bytes', 'memory_peak_bytes'];
                })
                ->once();
        }
    }

    // ------------------------------------------------------------------
    // 依頼AS-2(2026-09-03): 「診断が完了しました」メール(相談リクエストの
    // 有無にかかわらず送る)を、レポート(Word/PDF)生成の成功時点で送る。
    // ------------------------------------------------------------------

    public function test_sends_the_lead_diagnosis_completed_email_regardless_of_consultation_request(): void
    {
        Mail::fake();
        $analysis = $this->makeLeadAnalysis();
        $this->attachSendableBrandWheelResult($analysis);
        // consultation_requested_atは既定でnull(相談リクエストなし)のまま。

        (new GenerateLeadReportJob($analysis->id))->handle(
            app(ReportViewModelBuilder::class),
            app(WordReportGenerator::class),
            app(PdfReportGenerator::class),
            app(LeadDiagnosisCompletedNotifier::class),
        );

        Mail::assertSent(BrandWheelLeadDiagnosisCompletedMail::class, 1);
    }

    public function test_does_not_send_twice_when_the_job_completes_again_for_an_already_completed_analysis(): void
    {
        Mail::fake();
        $analysis = $this->makeLeadAnalysis();
        $this->attachSendableBrandWheelResult($analysis);

        $job = new GenerateLeadReportJob($analysis->id);
        $job->handle(app(ReportViewModelBuilder::class), app(WordReportGenerator::class), app(PdfReportGenerator::class), app(LeadDiagnosisCompletedNotifier::class));
        // 両フォーマットとも既にCompletedのため、2回目はformat単位の冪等性で
        // 再生成せずすぐ終端に達する(依頼AS-2のnotifyIfReady()自身も
        // 呼ばれる ―― 二重送信防止はAnalysis.lead_diagnosis_completed_
        // notified_atの条件付きUPDATEで防ぐ)。
        $job->handle(app(ReportViewModelBuilder::class), app(WordReportGenerator::class), app(PdfReportGenerator::class), app(LeadDiagnosisCompletedNotifier::class));

        Mail::assertSent(BrandWheelLeadDiagnosisCompletedMail::class, 1);
    }

    public function test_does_not_send_when_self_brand_wheel_result_is_not_eligible(): void
    {
        Mail::fake();
        $analysis = $this->makeLeadAnalysis();
        $this->attachSendableBrandWheelResult($analysis, ['axis_state_counts' => ['read' => 0, 'partial' => 0, 'unread' => 6]]);

        (new GenerateLeadReportJob($analysis->id))->handle(
            app(ReportViewModelBuilder::class),
            app(WordReportGenerator::class),
            app(PdfReportGenerator::class),
            app(LeadDiagnosisCompletedNotifier::class),
        );

        Mail::assertNotSent(BrandWheelLeadDiagnosisCompletedMail::class);
    }

    /**
     * レポート生成そのものが失敗した場合、「完了しました」というメールは
     * 送らない(依頼AS-2の絶対条件)。
     */
    public function test_does_not_send_when_report_generation_fails(): void
    {
        Mail::fake();
        $analysis = $this->makeLeadAnalysis();
        $this->attachSendableBrandWheelResult($analysis);

        $this->mock(WordReportGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->andThrow(new \RuntimeException('word generation exploded'));
        });

        try {
            (new GenerateLeadReportJob($analysis->id))->handle(
                app(ReportViewModelBuilder::class),
                app(WordReportGenerator::class),
                app(PdfReportGenerator::class),
                app(LeadDiagnosisCompletedNotifier::class),
            );
            $this->fail('RuntimeExceptionが投げられるはずでした。');
        } catch (\RuntimeException) {
            // 期待どおり: レポート生成失敗はこのJob自身の失敗として扱われる。
        }

        Mail::assertNotSent(BrandWheelLeadDiagnosisCompletedMail::class);
    }

    /**
     * メール送信自体が失敗しても、既に完了しているレポート生成の成否には
     * 影響しない(依頼AS-2、依頼者指定)。
     */
    public function test_report_generation_still_succeeds_when_the_completion_email_fails_to_send(): void
    {
        $analysis = $this->makeLeadAnalysis();
        $this->attachSendableBrandWheelResult($analysis);

        $this->mock(LeadDiagnosisCompletedNotifier::class, function ($mock) {
            $mock->shouldReceive('notifyIfReady')->andThrow(new \RuntimeException('mail service unavailable'));
        });

        (new GenerateLeadReportJob($analysis->id))->handle(
            app(ReportViewModelBuilder::class),
            app(WordReportGenerator::class),
            app(PdfReportGenerator::class),
            app(LeadDiagnosisCompletedNotifier::class),
        );

        $this->assertSame(2, Report::where('analysis_id', $analysis->id)->where('status', ReportGenerationStatus::Completed->value)->count());
    }
}
