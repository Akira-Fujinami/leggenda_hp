<?php

namespace Tests\Feature\Lead;

use App\Enums\AnalysisStatus;
use App\Enums\ReportGenerationStatus;
use App\Jobs\Analysis\StartAnalysisJob;
use App\Jobs\Report\GenerateLeadReportJob;
use App\Models\Analysis;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeadReportDownloadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<class-string>  $additionalFakedJobs
     */
    private function issueTokenAndAnalysis(array $additionalFakedJobs = []): array
    {
        // Queue::fake()の呼び出しはその都度フェイク対象を丸ごと置き換えるため、
        // このヘルパー内で単独呼び出すと、呼び出し元が別途フェイクした
        // GenerateLeadReportJob等の指定が上書きされて消えてしまう
        // (実際にJobが同期実行されてしまい、意図せずレポートが本当に
        // 生成されるという不具合の原因になった)。必ず1回のfake呼び出しに
        // まとめる。
        Queue::fake([StartAnalysisJob::class, ...$additionalFakedJobs]);
        // #B-1: store()がself_urlへ1回だけ到達性チェックを行う分。
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);

        $token = $this->postJson('/api/lead/onboarding', [
            'company_name' => '株式会社サンプル',
            'contact_name' => '山田太郎',
            'email' => 'lead@example.com',
            'privacy_policy_agreed' => true,
        ])->json('data.token');

        $analysisId = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com'])
            ->json('data.analysis_id');

        return [$token, $analysisId];
    }

    public function test_progress_dispatches_report_generation_exactly_once_when_the_analysis_completes(): void
    {
        [$token, $analysisId] = $this->issueTokenAndAnalysis([GenerateLeadReportJob::class]);
        Analysis::whereKey($analysisId)->update(['status' => AnalysisStatus::Completed]);

        $this->getJson("/api/lead/analyses/{$analysisId}/progress?token={$token}")->assertOk();
        $this->getJson("/api/lead/analyses/{$analysisId}/progress?token={$token}")->assertOk();
        $this->getJson("/api/lead/analyses/{$analysisId}/progress?token={$token}")->assertOk();

        Queue::assertPushed(GenerateLeadReportJob::class, 1);
        $this->assertSame(2, Report::where('analysis_id', $analysisId)->count());
    }

    public function test_report_generation_is_not_dispatched_while_still_processing(): void
    {
        [$token, $analysisId] = $this->issueTokenAndAnalysis([GenerateLeadReportJob::class]);
        Analysis::whereKey($analysisId)->update(['status' => AnalysisStatus::Running]);

        $this->getJson("/api/lead/analyses/{$analysisId}/progress?token={$token}")->assertOk();

        Queue::assertNotPushed(GenerateLeadReportJob::class);
        $this->assertSame(0, Report::where('analysis_id', $analysisId)->count());
    }

    public function test_report_generation_is_not_dispatched_for_a_fully_failed_analysis(): void
    {
        [$token, $analysisId] = $this->issueTokenAndAnalysis([GenerateLeadReportJob::class]);
        Analysis::whereKey($analysisId)->update(['status' => AnalysisStatus::Failed]);

        $this->getJson("/api/lead/analyses/{$analysisId}/progress?token={$token}")->assertOk();

        Queue::assertNotPushed(GenerateLeadReportJob::class);
    }

    public function test_results_reports_processing_status_before_generation_completes(): void
    {
        [$token, $analysisId] = $this->issueTokenAndAnalysis([GenerateLeadReportJob::class]);
        Analysis::whereKey($analysisId)->update(['status' => AnalysisStatus::Completed]);

        $response = $this->getJson("/api/lead/analyses/{$analysisId}/results?token={$token}");

        $response->assertOk();
        $response->assertJsonPath('data.reports.docx', 'processing');
        $response->assertJsonPath('data.reports.pdf', 'processing');
    }

    public function test_results_reports_ready_status_once_generation_completes(): void
    {
        [$token, $analysisId] = $this->issueTokenAndAnalysis();
        Analysis::whereKey($analysisId)->update(['status' => AnalysisStatus::Completed]);
        Report::factory()->create(['analysis_id' => $analysisId, 'format' => 'docx', 'status' => ReportGenerationStatus::Completed]);
        Report::factory()->create(['analysis_id' => $analysisId, 'format' => 'pdf', 'status' => ReportGenerationStatus::Completed]);

        $response = $this->getJson("/api/lead/analyses/{$analysisId}/results?token={$token}");

        $response->assertJsonPath('data.reports.docx', 'ready');
        $response->assertJsonPath('data.reports.pdf', 'ready');
    }

    public function test_download_succeeds_once_the_report_is_ready(): void
    {
        Storage::fake('analysis');
        [$token, $analysisId] = $this->issueTokenAndAnalysis();
        Storage::disk('analysis')->put("reports/{$analysisId}/report.pdf", '%PDF-fake-bytes');
        Report::factory()->create([
            'analysis_id' => $analysisId,
            'format' => 'pdf',
            'storage_path' => "reports/{$analysisId}/report.pdf",
            'status' => ReportGenerationStatus::Completed,
            'generated_at' => now(),
        ]);

        $response = $this->get("/api/lead/analyses/{$analysisId}/reports/pdf?token={$token}");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_download_returns_a_friendly_conflict_when_not_yet_ready(): void
    {
        [$token, $analysisId] = $this->issueTokenAndAnalysis();
        Report::factory()->create(['analysis_id' => $analysisId, 'format' => 'pdf', 'status' => ReportGenerationStatus::Pending]);

        $response = $this->getJson("/api/lead/analyses/{$analysisId}/reports/pdf?token={$token}");

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'REPORT_NOT_READY');
    }

    public function test_download_rejects_an_invalid_format(): void
    {
        [$token, $analysisId] = $this->issueTokenAndAnalysis();

        $response = $this->getJson("/api/lead/analyses/{$analysisId}/reports/exe?token={$token}");

        $response->assertNotFound();
    }

    public function test_download_returns_404_for_a_report_owned_by_a_different_lead_session(): void
    {
        Storage::fake('analysis');
        [, $analysisId] = $this->issueTokenAndAnalysis();
        Storage::disk('analysis')->put("reports/{$analysisId}/report.pdf", '%PDF-fake-bytes');
        Report::factory()->create([
            'analysis_id' => $analysisId,
            'format' => 'pdf',
            'storage_path' => "reports/{$analysisId}/report.pdf",
            'status' => ReportGenerationStatus::Completed,
        ]);

        $otherToken = $this->postJson('/api/lead/onboarding', [
            'company_name' => '別の会社',
            'contact_name' => '鈴木一郎',
            'email' => 'other-lead@example.com',
            'privacy_policy_agreed' => true,
        ])->json('data.token');

        $response = $this->getJson("/api/lead/analyses/{$analysisId}/reports/pdf?token={$otherToken}");

        $response->assertNotFound();
    }

    public function test_download_never_leaks_storage_path_or_error_details(): void
    {
        Storage::fake('analysis');
        [$token, $analysisId] = $this->issueTokenAndAnalysis();
        Report::factory()->create([
            'analysis_id' => $analysisId,
            'format' => 'pdf',
            'status' => ReportGenerationStatus::Failed,
            'error_message' => 'RuntimeException: something internal broke at /var/www/html/app/Services/Report/PdfReportGenerator.php',
        ]);

        $response = $this->getJson("/api/lead/analyses/{$analysisId}/reports/pdf?token={$token}");

        $response->assertStatus(409);
        $raw = $response->getContent();
        $this->assertStringNotContainsString('RuntimeException', $raw);
        $this->assertStringNotContainsString('/var/www/html', $raw);
    }
}
