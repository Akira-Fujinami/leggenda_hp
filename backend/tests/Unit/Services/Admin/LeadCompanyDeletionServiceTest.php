<?php

namespace Tests\Unit\Services\Admin;

use App\Exceptions\Admin\LeadCompanyDeletionBlockedException;
use App\Models\Analysis;
use App\Models\AnalysisAttachment;
use App\Models\LeadCompany;
use App\Models\LeadCompanyDeletion;
use App\Models\LeadSession;
use App\Models\Project;
use App\Enums\ReportGenerationStatus;
use App\Models\Report;
use App\Models\User;
use App\Services\Admin\LeadCompanyDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 依頼CI-2(2026-10-01): 会社単位の物理削除。削除は取り返しがつかないため
 * テストは厚くする(依頼者指定)。
 */
class LeadCompanyDeletionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): LeadCompanyDeletionService
    {
        return app(LeadCompanyDeletionService::class);
    }

    /**
     * @return array{company: LeadCompany, session: LeadSession, project: Project, analysis: Analysis}
     */
    private function makeDiagnosis(LeadCompany $company, ?LeadSession $session = null): array
    {
        $session ??= LeadSession::factory()->create();
        $user = User::factory()->create();
        $project = new Project(['name' => 'diagnosis-project']);
        $project->user_id = $user->id;
        $project->lead_session_id = $session->id;
        $project->lead_company_id = $company->id;
        $project->save();
        $analysis = Analysis::factory()->completed()->create(['project_id' => $project->id]);

        return ['company' => $company, 'session' => $session, 'project' => $project, 'analysis' => $analysis];
    }

    /**
     * 多社比較を模したProject(lead_session_id=null、AdminComparisonServiceと
     * 同じ形)。
     */
    private function makeComparison(LeadCompany $company): Project
    {
        $user = User::factory()->create();
        $project = new Project(['name' => 'comparison-project']);
        $project->user_id = $user->id;
        $project->lead_company_id = $company->id;
        $project->save();
        Analysis::factory()->completed()->create(['project_id' => $project->id]);

        return $project;
    }

    public function test_deletes_all_data_for_the_company_without_touching_other_companies(): void
    {
        Storage::fake('analysis');

        $companyA = LeadCompany::factory()->create(['company_name' => 'A社']);
        $dataA = $this->makeDiagnosis($companyA);
        Storage::disk('analysis')->put("attachments/{$dataA['analysis']->id}/deck.pptx", 'a-deck');
        AnalysisAttachment::factory()->create([
            'analysis_id' => $dataA['analysis']->id,
            'storage_path' => "attachments/{$dataA['analysis']->id}/deck.pptx",
            'size_bytes' => strlen('a-deck'),
        ]);
        Report::factory()->create([
            'analysis_id' => $dataA['analysis']->id,
            'format' => 'pdf',
            'storage_path' => "reports/{$dataA['analysis']->id}/report.pdf",
            'status' => ReportGenerationStatus::Completed,
        ]);
        Storage::disk('analysis')->put("reports/{$dataA['analysis']->id}/report.pdf", '%PDF-fake');

        $companyB = LeadCompany::factory()->create(['company_name' => 'B社']);
        $dataB = $this->makeDiagnosis($companyB);

        $this->service()->destroy($companyA, 'A社');

        $this->assertDatabaseMissing('lead_companies', ['id' => $companyA->id]);
        $this->assertDatabaseMissing('lead_sessions', ['id' => $dataA['session']->id]);
        $this->assertDatabaseMissing('projects', ['id' => $dataA['project']->id]);
        $this->assertDatabaseMissing('analyses', ['id' => $dataA['analysis']->id]);
        Storage::disk('analysis')->assertMissing("attachments/{$dataA['analysis']->id}/deck.pptx");
        Storage::disk('analysis')->assertMissing("reports/{$dataA['analysis']->id}/report.pdf");

        $this->assertDatabaseHas('lead_companies', ['id' => $companyB->id]);
        $this->assertDatabaseHas('lead_sessions', ['id' => $dataB['session']->id]);
        $this->assertDatabaseHas('projects', ['id' => $dataB['project']->id]);
        $this->assertDatabaseHas('analyses', ['id' => $dataB['analysis']->id]);
    }

    public function test_deletes_comparison_projects_with_null_lead_session_id_too(): void
    {
        $company = LeadCompany::factory()->create(['company_name' => '比較対象社']);
        $diagnosis = $this->makeDiagnosis($company);
        $comparison = $this->makeComparison($company);

        $record = $this->service()->destroy($company, '比較対象社');

        $this->assertDatabaseMissing('projects', ['id' => $diagnosis['project']->id]);
        $this->assertDatabaseMissing('projects', ['id' => $comparison->id]);
        $this->assertSame(1, $record->diagnoses_deleted_count);
        $this->assertSame(1, $record->comparisons_deleted_count);
    }

    public function test_company_with_zero_diagnoses_can_still_be_deleted(): void
    {
        $company = LeadCompany::factory()->create(['company_name' => '診断0件社']);

        $record = $this->service()->destroy($company, '診断0件社');

        $this->assertDatabaseMissing('lead_companies', ['id' => $company->id]);
        $this->assertSame(0, $record->sessions_deleted_count);
        $this->assertSame(0, $record->diagnoses_deleted_count);
        $this->assertSame(0, $record->comparisons_deleted_count);
    }

    public function test_blocks_deletion_when_an_analysis_is_running(): void
    {
        $company = LeadCompany::factory()->create(['company_name' => '実行中社']);
        $session = LeadSession::factory()->create();
        $user = User::factory()->create();
        $project = new Project(['name' => 'running-project']);
        $project->user_id = $user->id;
        $project->lead_session_id = $session->id;
        $project->lead_company_id = $company->id;
        $project->save();
        Analysis::factory()->running()->create(['project_id' => $project->id]);

        $this->expectException(LeadCompanyDeletionBlockedException::class);

        try {
            $this->service()->destroy($company, '実行中社');
        } finally {
            $this->assertDatabaseHas('lead_companies', ['id' => $company->id]);
            $this->assertDatabaseHas('projects', ['id' => $project->id]);
        }
    }

    public function test_blocks_deletion_when_the_session_is_shared_with_another_identified_company(): void
    {
        $companyA = LeadCompany::factory()->create(['company_name' => 'A社']);
        $companyB = LeadCompany::factory()->create(['company_name' => 'B社']);
        $sharedSession = LeadSession::factory()->create();
        $dataA = $this->makeDiagnosis($companyA, $sharedSession);
        // 同じセッションで、別の会社(B社)の診断も行われている。
        $this->makeDiagnosis($companyB, $sharedSession);

        try {
            $this->service()->destroy($companyA, 'A社');
            $this->fail('LeadCompanyDeletionBlockedExceptionが投げられるはず');
        } catch (LeadCompanyDeletionBlockedException $e) {
            $this->assertNotEmpty($e->preview->blockingCompanies);
            $this->assertSame('B社', $e->preview->blockingCompanies[0]['company_name']);
        }

        $this->assertDatabaseHas('lead_companies', ['id' => $companyA->id]);
        $this->assertDatabaseHas('projects', ['id' => $dataA['project']->id]);
    }

    /**
     * 対照実験: 同じセッションが使われていても、もう一方のProjectが
     * lead_company_id=null(解決失敗)の場合は「別の会社」として扱わず
     * ブロックしないこと。
     */
    public function test_does_not_block_when_the_shared_session_other_project_has_no_identified_company(): void
    {
        $company = LeadCompany::factory()->create(['company_name' => '単独社']);
        $session = LeadSession::factory()->create();
        $dataA = $this->makeDiagnosis($company, $session);

        $user = User::factory()->create();
        $unresolvedProject = new Project(['name' => 'unresolved-project']);
        $unresolvedProject->user_id = $user->id;
        $unresolvedProject->lead_session_id = $session->id;
        $unresolvedProject->lead_company_id = null;
        $unresolvedProject->save();

        $this->service()->destroy($company, '単独社');

        $this->assertDatabaseMissing('lead_companies', ['id' => $company->id]);
        $this->assertDatabaseMissing('projects', ['id' => $dataA['project']->id]);
    }

    public function test_throws_a_validation_exception_when_the_confirmed_name_does_not_match(): void
    {
        $company = LeadCompany::factory()->create(['company_name' => '正しい会社名']);

        $this->expectException(ValidationException::class);

        try {
            $this->service()->destroy($company, '違う会社名');
        } finally {
            $this->assertDatabaseHas('lead_companies', ['id' => $company->id]);
        }
    }

    /**
     * 依頼CI-2必須: 前後の空白は無視するが、全角/半角の違いは無視しない
     * (厳格な完全一致、依頼者への報告どおり)。
     */
    public function test_name_confirmation_ignores_surrounding_whitespace_but_not_width_differences(): void
    {
        $company = LeadCompany::factory()->create(['company_name' => 'ＡＢＣ株式会社']);

        // 前後の空白は無視される。
        $this->service()->destroy($company, "  ＡＢＣ株式会社\n");
        $this->assertDatabaseMissing('lead_companies', ['id' => $company->id]);

        // 半角で入力した場合は一致しない(全角/半角の正規化はしない)。
        $company2 = LeadCompany::factory()->create(['company_name' => 'ＡＢＣ株式会社']);
        try {
            $this->service()->destroy($company2, 'ABC株式会社');
            $this->fail('ValidationExceptionが投げられるはず');
        } catch (ValidationException) {
            $this->assertDatabaseHas('lead_companies', ['id' => $company2->id]);
        }
    }

    public function test_audit_record_contains_no_personally_identifiable_fields(): void
    {
        $company = LeadCompany::factory()->create([
            'company_name' => '秘密株式会社',
            'primary_contact_name' => '山田太郎',
            'primary_contact_email' => 'secret@example.com',
        ]);
        $this->makeDiagnosis($company);

        $record = $this->service()->destroy($company, '秘密株式会社');

        $attributes = $record->getAttributes();
        $encoded = (string) json_encode($attributes, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('秘密株式会社', $encoded);
        $this->assertStringNotContainsString('山田太郎', $encoded);
        $this->assertStringNotContainsString('secret@example.com', $encoded);
        $this->assertSame($company->id, $record->lead_company_id);
    }

    public function test_file_deletion_failure_is_recorded_without_blocking_db_deletion(): void
    {
        $company = LeadCompany::factory()->create(['company_name' => '失敗社']);
        $data = $this->makeDiagnosis($company);
        Report::factory()->create([
            'analysis_id' => $data['analysis']->id,
            'format' => 'pdf',
            'storage_path' => "reports/{$data['analysis']->id}/report.pdf",
            'status' => ReportGenerationStatus::Completed,
        ]);

        $diskMock = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $diskMock->shouldReceive('delete')->andThrow(new \RuntimeException('simulated failure'));
        $diskMock->shouldReceive('deleteDirectory')->andReturnTrue();
        $diskMock->shouldReceive('exists')->andReturnFalse();
        $diskMock->shouldReceive('allFiles')->andReturn([]);
        $diskMock->shouldReceive('size')->andReturn(0);
        Storage::shouldReceive('disk')->with('analysis')->andReturn($diskMock);

        $record = $this->service()->destroy($company, '失敗社');

        $this->assertDatabaseMissing('lead_companies', ['id' => $company->id]);
        $this->assertGreaterThan(0, $record->file_deletion_failures_count);
    }

    public function test_preview_does_not_delete_anything(): void
    {
        $company = LeadCompany::factory()->create(['company_name' => 'プレビュー社']);
        $data = $this->makeDiagnosis($company);

        $preview = $this->service()->preview($company);

        $this->assertSame(1, $preview->diagnosesCount);
        $this->assertFalse($preview->isBlocked());
        $this->assertDatabaseHas('lead_companies', ['id' => $company->id]);
        $this->assertDatabaseHas('projects', ['id' => $data['project']->id]);
    }
}
