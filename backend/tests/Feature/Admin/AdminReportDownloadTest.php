<?php

namespace Tests\Feature\Admin;

use App\Enums\AnalysisStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportGenerationStatus;
use App\Models\Analysis;
use App\Models\LeadCompany;
use App\Models\LeadSession;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼AG-1(2026-08-27): 管理画面から、無料診断のレポート(PDF/Word)を
 * ダウンロードできるようにする。既存の多社比較レポートのダウンロード導線
 * (依頼AC)は変更しないため、回帰確認としてこのテストファイルでまとめて
 * 検証する。
 */
class AdminReportDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
    }

    private function asAdmin(): static
    {
        return $this->withSession(['admin_authenticated' => true]);
    }

    /**
     * source_analysis_idを持たない、通常の無料診断。
     */
    private function makeLeadAnalysis(): Analysis
    {
        $company = LeadCompany::factory()->create();
        $leadSession = LeadSession::factory()->create();
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->lead_company_id = $company->id;
        $project->save();

        return Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
    }

    /**
     * source_analysis_idを持つ、管理者起点の多社比較。
     */
    private function makeComparisonAnalysis(): Analysis
    {
        $company = LeadCompany::factory()->create();

        $sourceProject = new Project(['name' => '起点']);
        $sourceProject->user_id = User::factory()->create()->id;
        $sourceProject->lead_company_id = $company->id;
        $sourceProject->save();
        $sourceAnalysis = Analysis::factory()->create(['project_id' => $sourceProject->id, 'status' => AnalysisStatus::Completed]);

        $project = new Project(['name' => '比較']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_company_id = $company->id;
        $project->save();

        return Analysis::factory()->create([
            'project_id' => $project->id,
            'status' => AnalysisStatus::Completed,
            'source_analysis_id' => $sourceAnalysis->id,
        ]);
    }

    private function makeCompletedReport(Analysis $analysis, ReportFormat $format, string $contents = 'dummy-bytes'): Report
    {
        $path = "reports/{$analysis->id}/report.{$format->value}";
        Storage::disk('analysis')->put($path, $contents);

        return Report::factory()->create([
            'analysis_id' => $analysis->id,
            'format' => $format->value,
            'storage_path' => $path,
            'status' => ReportGenerationStatus::Completed->value,
            'generated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------
    // AG-1: 無料診断のレポートダウンロード(新設)。
    // ------------------------------------------------------------------

    public function test_admin_can_download_the_pdf_report_of_a_lead_analysis(): void
    {
        $analysis = $this->makeLeadAnalysis();
        $this->makeCompletedReport($analysis, ReportFormat::Pdf, 'pdf-bytes');

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}/report/pdf");

        $response->assertOk();
        $this->assertSame('pdf-bytes', $response->streamedContent());
        // 依頼AG-1: リード向けdownloadReport()と同じファイル名にする。
        $this->assertStringContainsString('診断レポート.pdf', rawurldecode((string) $response->headers->get('Content-Disposition')));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_admin_can_download_the_word_report_of_a_lead_analysis(): void
    {
        $analysis = $this->makeLeadAnalysis();
        $this->makeCompletedReport($analysis, ReportFormat::Docx, 'docx-bytes');

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}/report/docx");

        $response->assertOk();
        $this->assertSame('docx-bytes', $response->streamedContent());
        $this->assertStringContainsString('診断レポート.docx', rawurldecode((string) $response->headers->get('Content-Disposition')));
    }

    public function test_lead_report_download_is_not_found_when_no_report_row_exists(): void
    {
        $analysis = $this->makeLeadAnalysis();

        $this->asAdmin()->get("/admin/analyses/{$analysis->id}/report/pdf")->assertNotFound();
    }

    public function test_lead_report_download_is_not_found_when_status_is_not_completed(): void
    {
        $analysis = $this->makeLeadAnalysis();
        Report::factory()->create([
            'analysis_id' => $analysis->id,
            'format' => ReportFormat::Pdf->value,
            'storage_path' => '',
            'status' => ReportGenerationStatus::Pending->value,
        ]);

        $this->asAdmin()->get("/admin/analyses/{$analysis->id}/report/pdf")->assertNotFound();
    }

    public function test_lead_report_download_is_not_found_when_the_file_is_missing_from_disk(): void
    {
        $analysis = $this->makeLeadAnalysis();
        Report::factory()->create([
            'analysis_id' => $analysis->id,
            'format' => ReportFormat::Pdf->value,
            'storage_path' => "reports/{$analysis->id}/report.pdf",
            'status' => ReportGenerationStatus::Completed->value,
            'generated_at' => now(),
        ]);
        // Storage::fake済みのため、実際にはファイルを書き込んでいない
        // (=ディスク上に無い状態を再現)。

        $this->asAdmin()->get("/admin/analyses/{$analysis->id}/report/pdf")->assertNotFound();
    }

    public function test_lead_report_download_rejects_an_unknown_format(): void
    {
        $analysis = $this->makeLeadAnalysis();
        $this->makeCompletedReport($analysis, ReportFormat::Pdf);

        $this->asAdmin()->get("/admin/analyses/{$analysis->id}/report/xlsx")->assertNotFound();
    }

    /**
     * 多社比較(source_analysis_idが非null)は、このエンドポイントの対象外
     * (既存のcomparison-report専用エンドポイントのまま)。
     */
    public function test_lead_report_download_is_not_found_for_a_comparison_analysis(): void
    {
        $analysis = $this->makeComparisonAnalysis();
        $this->makeCompletedReport($analysis, ReportFormat::Pdf);

        $this->asAdmin()->get("/admin/analyses/{$analysis->id}/report/pdf")->assertNotFound();
    }

    public function test_unauthenticated_lead_report_download_does_not_return_the_file(): void
    {
        $analysis = $this->makeLeadAnalysis();
        $this->makeCompletedReport($analysis, ReportFormat::Pdf, 'pdf-bytes');

        $response = $this->get("/admin/analyses/{$analysis->id}/report/pdf");

        // admin.auth配下のGETは、未認証時401ではなく認証モーダルのみの
        // ビュー(admin.guest)を返す仕様(EnsureAdminAuthenticated参照)。
        // 重要なのはファイルの中身が一切返らないこと。
        $response->assertOk();
        $this->assertNull($response->headers->get('Content-Disposition'));
    }

    /**
     * 診断詳細画面に、無料診断のダウンロードリンクが表示されること。
     */
    public function test_the_analysis_show_page_shows_a_download_link_for_a_completed_lead_report(): void
    {
        $analysis = $this->makeLeadAnalysis();
        $this->makeCompletedReport($analysis, ReportFormat::Pdf);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee(route('admin.analyses.lead-report.download', [$analysis->id, 'pdf'], false), false);
    }

    /**
     * status='skipped'(情報量不足で意図的に見送った状態)の表示・挙動は
     * 変更しない ―― ダウンロードリンクは出ない(completedではないため)。
     */
    public function test_a_skipped_report_shows_no_download_link_and_its_label_is_unchanged(): void
    {
        $analysis = $this->makeLeadAnalysis();
        Report::factory()->create([
            'analysis_id' => $analysis->id,
            'format' => ReportFormat::Pdf->value,
            'storage_path' => '',
            'status' => ReportGenerationStatus::Skipped->value,
        ]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee('見送り(診断回数は消費していません)');
        $response->assertDontSee(route('admin.analyses.lead-report.download', [$analysis->id, 'pdf'], false), false);
    }

    // ------------------------------------------------------------------
    // 回帰確認: 多社比較レポートのダウンロード導線(依頼AC、無変更)。
    // ------------------------------------------------------------------

    public function test_admin_can_still_download_the_comparison_report_of_a_comparison_analysis(): void
    {
        $analysis = $this->makeComparisonAnalysis();
        $this->makeCompletedReport($analysis, ReportFormat::Pdf, 'comparison-pdf-bytes');

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}/comparison-report");

        $response->assertOk();
        $this->assertSame('comparison-pdf-bytes', $response->streamedContent());
    }

    public function test_comparison_report_download_is_still_not_found_for_a_regular_lead_analysis(): void
    {
        $analysis = $this->makeLeadAnalysis();
        $this->makeCompletedReport($analysis, ReportFormat::Pdf);

        $this->asAdmin()->get("/admin/analyses/{$analysis->id}/comparison-report")->assertNotFound();
    }

    public function test_the_analysis_show_page_still_shows_the_comparison_download_link_for_a_comparison_analysis(): void
    {
        $analysis = $this->makeComparisonAnalysis();
        $this->makeCompletedReport($analysis, ReportFormat::Pdf);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee(route('admin.analyses.comparison-report.download', $analysis->id, false), false);
        // 比較Analysisには、新設のlead-report.downloadリンクは出ない
        // (どちらも対象がsource_analysis_idの有無で排他のため)。
        $response->assertDontSee(route('admin.analyses.lead-report.download', [$analysis->id, 'pdf'], false), false);
    }
}
