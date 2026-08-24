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
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 診断企業一覧・詳細・営業ステータス・営業メモ(依頼#9〜#14・#28)。
 */
class CompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): static
    {
        return $this->withSession(['admin_authenticated' => true]);
    }

    /**
     * @return array{company: LeadCompany, analyses: list<Analysis>}
     */
    private function makeCompanyWithDiagnoses(int $count, array $companyAttrs = []): array
    {
        $company = LeadCompany::factory()->create($companyAttrs);
        $sentinel = User::factory()->create();

        $analyses = [];
        for ($i = 0; $i < $count; $i++) {
            $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);
            Website::factory()->for($project)->create(['is_primary' => true, 'url' => 'https://self-'.$company->id.'-'.$i.'.example.com']);
            $analyses[] = Analysis::factory()->for($project)->create([
                'created_by' => $sentinel->id,
                'status' => AnalysisStatus::Completed,
                'created_at' => now()->subDays($count - $i),
            ]);
        }

        return ['company' => $company, 'analyses' => $analyses];
    }

    public function test_a_company_diagnosed_three_times_appears_as_a_single_row_with_count_three(): void
    {
        $this->makeCompanyWithDiagnoses(3, ['company_name' => '株式会社AAA']);

        $response = $this->asAdmin()->get('/admin/companies');

        $response->assertOk();
        $response->assertSee('株式会社AAA');
        $response->assertSee('3回');
        $this->assertSame(1, LeadCompany::query()->where('company_name', '株式会社AAA')->count());
    }

    public function test_a_different_company_appears_as_a_separate_row(): void
    {
        $this->makeCompanyWithDiagnoses(1, ['company_name' => '株式会社AAA']);
        $this->makeCompanyWithDiagnoses(1, ['company_name' => '株式会社BBB']);

        $response = $this->asAdmin()->get('/admin/companies');

        $response->assertOk();
        $response->assertSee('株式会社AAA');
        $response->assertSee('株式会社BBB');
        $this->assertSame(2, LeadCompany::query()->count());
    }

    public function test_a_company_with_two_or_more_diagnoses_is_flagged_as_re_diagnosed_on_the_dashboard(): void
    {
        $this->makeCompanyWithDiagnoses(2, ['company_name' => '株式会社再診断']);
        $this->makeCompanyWithDiagnoses(1, ['company_name' => '株式会社初回のみ']);

        $response = $this->asAdmin()->get('/admin/dashboard');

        $response->assertOk();
        $response->assertViewHas('kpis', fn (array $kpis) => $kpis['re_diagnosed_count'] === 1);
    }

    public function test_company_detail_page_shows_only_that_companys_diagnosis_history(): void
    {
        $companyA = $this->makeCompanyWithDiagnoses(2, ['company_name' => '株式会社AAA']);
        $companyB = $this->makeCompanyWithDiagnoses(1, ['company_name' => '株式会社BBB']);

        $response = $this->asAdmin()->get('/admin/companies/'.$companyA['company']->id);

        $response->assertOk();
        $response->assertSee('株式会社AAA');
        foreach ($companyA['analyses'] as $analysis) {
            $response->assertSee((string) $analysis->id);
        }

        // 他企業(BBB)の診断履歴が混ざっていないこと。
        $bbbUrls = collect($companyB['analyses'])->map(fn (Analysis $a) => $a->project->websites->first()->url);
        foreach ($bbbUrls as $url) {
            $response->assertDontSee($url);
        }
    }

    public function test_sales_status_can_be_updated(): void
    {
        $company = LeadCompany::factory()->create(['sales_status' => 'uncontacted']);

        $response = $this->asAdmin()->patch("/admin/companies/{$company->id}/sales-status", [
            'sales_status' => 'meeting',
        ]);

        $response->assertRedirect();
        $this->assertSame('meeting', $company->fresh()->sales_status);
    }

    public function test_sales_status_rejects_an_invalid_value(): void
    {
        $company = LeadCompany::factory()->create(['sales_status' => 'uncontacted']);

        $response = $this->asAdmin()->patch("/admin/companies/{$company->id}/sales-status", [
            'sales_status' => 'not-a-real-status',
        ]);

        $response->assertSessionHasErrors('sales_status');
        $this->assertSame('uncontacted', $company->fresh()->sales_status);
    }

    public function test_sales_note_can_be_saved(): void
    {
        $company = LeadCompany::factory()->create(['sales_note' => null]);

        $response = $this->asAdmin()->patch("/admin/companies/{$company->id}/sales-note", [
            'sales_note' => "8/18 電話予定。\n採用ページ改善に関心あり。",
        ]);

        $response->assertRedirect();
        $this->assertSame("8/18 電話予定。\n採用ページ改善に関心あり。", $company->fresh()->sales_note);
    }

    public function test_company_list_can_be_searched_by_name(): void
    {
        $this->makeCompanyWithDiagnoses(1, ['company_name' => '株式会社ターゲット']);
        $this->makeCompanyWithDiagnoses(1, ['company_name' => '無関係株式会社']);

        $response = $this->asAdmin()->get('/admin/companies?search='.urlencode('ターゲット'));

        $response->assertOk();
        $response->assertSee('株式会社ターゲット');
        $response->assertDontSee('無関係株式会社');
    }

    public function test_company_list_can_filter_by_re_diagnosed(): void
    {
        $this->makeCompanyWithDiagnoses(2, ['company_name' => '株式会社複数回']);
        $this->makeCompanyWithDiagnoses(1, ['company_name' => '株式会社一回のみ']);

        $response = $this->asAdmin()->get('/admin/companies?re_diagnosed=yes');

        $response->assertOk();
        $response->assertSee('株式会社複数回');
        $response->assertDontSee('株式会社一回のみ');
    }

    /**
     * 依頼B-4: 本番に導線が無いトークン再発行の代替として、営業が管理画面から
     * 個別のLeadSessionのanalyses_usedを0へリセットできること。
     */
    public function test_company_detail_page_shows_the_reset_control_for_a_lead_linked_diagnosis(): void
    {
        $company = LeadCompany::factory()->create();
        $sentinel = User::factory()->create();
        $leadSession = LeadSession::factory()->create(['analyses_used' => 1]);
        $project = Project::factory()->for($sentinel)->create([
            'lead_company_id' => $company->id,
            'lead_session_id' => $leadSession->id,
        ]);
        Website::factory()->for($project)->create(['is_primary' => true]);
        Analysis::factory()->for($project)->create(['created_by' => $sentinel->id, 'status' => AnalysisStatus::Completed]);

        $response = $this->asAdmin()->get("/admin/companies/{$company->id}");

        $response->assertOk();
        $response->assertSee('診断回数をリセット');
        $response->assertSee(route('admin.lead-sessions.reset-analyses-used', $leadSession->id, false), false);
    }

    /**
     * #c: 実行中(Pending/Queued/Running)の診断がある行にだけ、警告と
     * 強制終了ボタンを表示する。診断回数リセットだけでは復旧しないことを
     * 営業に気づかせるための表示(依頼者指摘)。
     */
    public function test_company_detail_page_shows_a_warning_and_force_terminate_button_for_a_stuck_analysis(): void
    {
        $company = LeadCompany::factory()->create();
        $sentinel = User::factory()->create();
        $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);
        Website::factory()->for($project)->create(['is_primary' => true]);
        $stuck = Analysis::factory()->for($project)->create(['created_by' => $sentinel->id, 'status' => AnalysisStatus::Running]);

        $response = $this->asAdmin()->get("/admin/companies/{$company->id}");

        $response->assertOk();
        $response->assertSee('実行中');
        $response->assertSee('診断を強制終了');
        $response->assertSee(route('admin.analyses.force-terminate', $stuck->id, false), false);
    }

    public function test_company_detail_page_does_not_show_the_stuck_warning_for_a_completed_analysis(): void
    {
        $company = LeadCompany::factory()->create();
        $sentinel = User::factory()->create();
        $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);
        Website::factory()->for($project)->create(['is_primary' => true]);
        Analysis::factory()->for($project)->create(['created_by' => $sentinel->id, 'status' => AnalysisStatus::Completed]);

        $response = $this->asAdmin()->get("/admin/companies/{$company->id}");

        $response->assertOk();
        $response->assertDontSee('診断を強制終了');
    }

    public function test_admin_can_reset_a_lead_sessions_analyses_used(): void
    {
        Log::spy();
        $leadSession = LeadSession::factory()->create(['analyses_used' => 1]);

        $response = $this->asAdmin()->patch("/admin/lead-sessions/{$leadSession->id}/reset-analyses-used");

        $response->assertRedirect();
        $this->assertSame(0, $leadSession->fresh()->analyses_used);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context = []) use ($leadSession) {
                return $message === 'Admin reset lead session analyses_used'
                    && $context['lead_session_id'] === $leadSession->id
                    && $context['previous_analyses_used'] === 1
                    && isset($context['ip']);
            });
    }

    /**
     * リクエストボディに何を積んでも0固定になること(依頼要件:
     * 「任意の数値を入れられないこと」)。
     */
    public function test_reset_ignores_any_analyses_used_value_in_the_request_body(): void
    {
        $leadSession = LeadSession::factory()->create(['analyses_used' => 1]);

        $this->asAdmin()->patch("/admin/lead-sessions/{$leadSession->id}/reset-analyses-used", [
            'analyses_used' => 99,
        ]);

        $this->assertSame(0, $leadSession->fresh()->analyses_used);
    }

    public function test_reset_requires_admin_authentication(): void
    {
        $leadSession = LeadSession::factory()->create(['analyses_used' => 1]);

        $response = $this->patch("/admin/lead-sessions/{$leadSession->id}/reset-analyses-used");

        $response->assertStatus(401);
        $this->assertSame(1, $leadSession->fresh()->analyses_used);
    }
}
