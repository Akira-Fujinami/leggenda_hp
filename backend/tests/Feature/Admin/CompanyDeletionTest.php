<?php

namespace Tests\Feature\Admin;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\LeadCompany;
use App\Models\LeadSession;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 依頼CI-2(2026-10-01): 管理画面からの会社単位の物理削除。削除は取り返しが
 * つかないため、画面全体(確認画面→実行)の経路を厚く検証する。
 */
class CompanyDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): static
    {
        return $this->withSession(['admin_authenticated' => true]);
    }

    /**
     * @return array{company: LeadCompany, session: LeadSession, project: Project, analysis: Analysis}
     */
    private function makeDiagnosis(LeadCompany $company, ?LeadSession $session = null, AnalysisStatus $status = AnalysisStatus::Completed): array
    {
        $session ??= LeadSession::factory()->create();
        $sentinel = User::factory()->create();
        $project = Project::factory()->for($sentinel)->create([
            'lead_company_id' => $company->id,
            'lead_session_id' => $session->id,
        ]);
        Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->create(['created_by' => $sentinel->id, 'status' => $status]);

        return ['company' => $company, 'session' => $session, 'project' => $project, 'analysis' => $analysis];
    }

    public function test_confirm_screen_shows_the_breakdown(): void
    {
        $company = LeadCompany::factory()->create(['company_name' => '株式会社テスト']);
        $this->makeDiagnosis($company);

        $response = $this->asAdmin()->get("/admin/companies/{$company->id}/delete");

        $response->assertOk();
        $response->assertSee('株式会社テスト');
        $response->assertSee('この操作は元に戻せません', false);
        $response->assertSee('完全に削除する');
    }

    public function test_confirm_screen_hides_the_delete_form_when_an_analysis_is_running(): void
    {
        $company = LeadCompany::factory()->create(['company_name' => '実行中株式会社']);
        $this->makeDiagnosis($company, status: AnalysisStatus::Running);

        $response = $this->asAdmin()->get("/admin/companies/{$company->id}/delete");

        $response->assertOk();
        $response->assertDontSee('完全に削除する');
        $response->assertSee('実行中のものがあるため', false);
    }

    public function test_confirm_screen_hides_the_delete_form_when_session_spans_another_company(): void
    {
        $companyA = LeadCompany::factory()->create(['company_name' => 'A社']);
        $companyB = LeadCompany::factory()->create(['company_name' => 'B社']);
        $sharedSession = LeadSession::factory()->create();
        $this->makeDiagnosis($companyA, $sharedSession);
        $this->makeDiagnosis($companyB, $sharedSession);

        $response = $this->asAdmin()->get("/admin/companies/{$companyA->id}/delete");

        $response->assertOk();
        $response->assertDontSee('完全に削除する');
        $response->assertSee('B社', false);
    }

    public function test_destroy_deletes_the_company_and_redirects_to_the_index_with_a_status_message(): void
    {
        $company = LeadCompany::factory()->create(['company_name' => '削除対象株式会社']);
        $this->makeDiagnosis($company);

        $response = $this->asAdmin()
            ->from("/admin/companies/{$company->id}/delete")
            ->delete("/admin/companies/{$company->id}", ['confirmation_company_name' => '削除対象株式会社']);

        $response->assertRedirect('/admin/companies');
        $response->assertSessionHas('status');
        $this->assertDatabaseMissing('lead_companies', ['id' => $company->id]);
    }

    public function test_destroy_does_not_delete_other_companies_data(): void
    {
        $companyA = LeadCompany::factory()->create(['company_name' => '削除対象社']);
        $dataA = $this->makeDiagnosis($companyA);
        $companyB = LeadCompany::factory()->create(['company_name' => '維持される社']);
        $dataB = $this->makeDiagnosis($companyB);

        $this->asAdmin()->delete("/admin/companies/{$companyA->id}", ['confirmation_company_name' => '削除対象社']);

        $this->assertDatabaseMissing('projects', ['id' => $dataA['project']->id]);
        $this->assertDatabaseHas('lead_companies', ['id' => $companyB->id]);
        $this->assertDatabaseHas('projects', ['id' => $dataB['project']->id]);
    }

    public function test_destroy_rejects_a_mismatched_company_name_and_deletes_nothing(): void
    {
        $company = LeadCompany::factory()->create(['company_name' => '正しい名前']);
        $this->makeDiagnosis($company);

        $response = $this->asAdmin()
            ->from("/admin/companies/{$company->id}/delete")
            ->delete("/admin/companies/{$company->id}", ['confirmation_company_name' => '間違った名前']);

        $response->assertSessionHasErrors('confirmation_company_name');
        $this->assertDatabaseHas('lead_companies', ['id' => $company->id]);
    }

    public function test_destroy_rejects_when_an_analysis_is_running_and_deletes_nothing(): void
    {
        $company = LeadCompany::factory()->create(['company_name' => '実行中社']);
        $data = $this->makeDiagnosis($company, status: AnalysisStatus::Running);

        $response = $this->asAdmin()
            ->from("/admin/companies/{$company->id}/delete")
            ->delete("/admin/companies/{$company->id}", ['confirmation_company_name' => '実行中社']);

        $response->assertRedirect("/admin/companies/{$company->id}/delete");
        $this->assertDatabaseHas('lead_companies', ['id' => $company->id]);
        $this->assertDatabaseHas('analyses', ['id' => $data['analysis']->id]);
    }

    public function test_destroy_rejects_when_session_spans_another_company_and_deletes_nothing(): void
    {
        $companyA = LeadCompany::factory()->create(['company_name' => 'A社']);
        $companyB = LeadCompany::factory()->create(['company_name' => 'B社']);
        $sharedSession = LeadSession::factory()->create();
        $dataA = $this->makeDiagnosis($companyA, $sharedSession);
        $this->makeDiagnosis($companyB, $sharedSession);

        $this->asAdmin()->delete("/admin/companies/{$companyA->id}", ['confirmation_company_name' => 'A社']);

        $this->assertDatabaseHas('lead_companies', ['id' => $companyA->id]);
        $this->assertDatabaseHas('projects', ['id' => $dataA['project']->id]);
    }

    public function test_a_company_with_zero_diagnoses_can_be_deleted(): void
    {
        $company = LeadCompany::factory()->create(['company_name' => '診断0件社']);

        $response = $this->asAdmin()->delete("/admin/companies/{$company->id}", ['confirmation_company_name' => '診断0件社']);

        $response->assertRedirect('/admin/companies');
        $this->assertDatabaseMissing('lead_companies', ['id' => $company->id]);
    }

    public function test_unauthenticated_request_cannot_view_the_confirm_screen(): void
    {
        $company = LeadCompany::factory()->create();

        $response = $this->get("/admin/companies/{$company->id}/delete");

        $response->assertOk();
        $response->assertDontSee('完全に削除する');
        $this->assertDatabaseHas('lead_companies', ['id' => $company->id]);
    }

    /**
     * 依頼CJ-2(2026-10-01): 無料診断を経由しない比較(起点なし、担当者情報
     * null)で登録された企業も、既存のLeadCompanyDeletionServiceで問題なく
     * 削除できること(CIの削除処理ロジック自体はCJで変更していない ――
     * 削除対象の特定がprojects.lead_company_idベースであり、無料診断の
     * 有無に依存しないため)。
     */
    public function test_a_company_created_only_via_a_standalone_comparison_can_be_deleted(): void
    {
        $company = \App\Models\LeadCompany::factory()->create([
            'company_name' => '単独比較のみの会社',
            'primary_contact_name' => null,
            'primary_contact_email' => null,
        ]);
        $sentinel = User::factory()->create();
        $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id, 'lead_session_id' => null]);
        Website::factory()->for($project)->create(['is_primary' => true]);
        Analysis::factory()->for($project)->create([
            'created_by' => $sentinel->id,
            'kind' => \App\Enums\AnalysisKind::AdminComparison,
            'status' => AnalysisStatus::Completed,
        ]);

        $response = $this->asAdmin()
            ->from("/admin/companies/{$company->id}/delete")
            ->delete("/admin/companies/{$company->id}", ['confirmation_company_name' => '単独比較のみの会社']);

        $response->assertRedirect('/admin/companies');
        $this->assertDatabaseMissing('lead_companies', ['id' => $company->id]);
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_unauthenticated_request_cannot_delete(): void
    {
        $company = LeadCompany::factory()->create();

        $response = $this->delete("/admin/companies/{$company->id}", ['confirmation_company_name' => $company->company_name]);

        // EnsureAdminAuthenticatedはGET以外の未認証リクエストを401で拒否する
        // (admin.guestビューを返すのはGETのみ)。
        $response->assertStatus(401);
        $this->assertDatabaseHas('lead_companies', ['id' => $company->id]);
    }
}
