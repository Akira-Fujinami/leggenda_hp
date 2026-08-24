<?php

namespace Tests\Feature\Admin;

use App\Enums\AnalysisStatus;
use App\Enums\ReportGenerationStatus;
use App\Models\Analysis;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 管理画面の診断詳細(/admin/analyses/{id})に、レポートのSkipped
 * (見送り・診断回数は消費していない)とFailed(生成失敗・診断回数は消費済み)を
 * 見分けられるラベルと、診断回数消費(lead_quota_consumed_at)の有無を
 * 表示する(2026-08-24追加、依頼者指定)。営業が「このリードにトークンを
 * 再発行すべきか」を判断する材料になる。
 */
class AnalysisShowReportStatusTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): static
    {
        return $this->withSession(['admin_authenticated' => true]);
    }

    private function makeAnalysis(AnalysisStatus $status, ?\DateTimeInterface $leadQuotaConsumedAt = null): Analysis
    {
        $sentinel = User::factory()->create();
        $company = LeadCompany::factory()->create();
        $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);

        return Analysis::factory()->for($project)->create([
            'created_by' => $sentinel->id,
            'status' => $status,
            'lead_quota_consumed_at' => $leadQuotaConsumedAt,
        ]);
    }

    public function test_skipped_report_is_labeled_as_not_consuming_the_diagnosis_quota(): void
    {
        $analysis = $this->makeAnalysis(AnalysisStatus::Partial);
        Report::factory()->create(['analysis_id' => $analysis->id, 'format' => 'docx', 'status' => ReportGenerationStatus::Skipped]);
        Report::factory()->create(['analysis_id' => $analysis->id, 'format' => 'pdf', 'status' => ReportGenerationStatus::Skipped]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee('見送り(診断回数は消費していません)', false);
        $response->assertSee('未消費');
        $response->assertDontSee('生成失敗');
    }

    public function test_failed_report_is_labeled_as_having_consumed_the_diagnosis_quota(): void
    {
        $consumedAt = now();
        $analysis = $this->makeAnalysis(AnalysisStatus::Partial, $consumedAt);
        Report::factory()->create(['analysis_id' => $analysis->id, 'format' => 'docx', 'status' => ReportGenerationStatus::Failed]);
        Report::factory()->create(['analysis_id' => $analysis->id, 'format' => 'pdf', 'status' => ReportGenerationStatus::Failed]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee('生成失敗(診断回数は消費済みです)', false);
        $response->assertSee('消費済み');
        $response->assertSee($consumedAt->format('Y/n/j'));
        $response->assertDontSee('見送り');
    }

    /**
     * GET(通常のページ表示)は、EnsureAdminAuthenticatedがURLをそのまま
     * ログインモーダルのみのビューへ差し替える設計(AdminAuthTest参照)の
     * ため、未認証でも200で返る ―― 診断詳細の中身は出さない。
     */
    public function test_show_requires_admin_authentication(): void
    {
        $analysis = $this->makeAnalysis(AnalysisStatus::Completed);

        $response = $this->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee('管理者ログイン');
        $response->assertDontSee("診断詳細 #{$analysis->id}");
    }
}
