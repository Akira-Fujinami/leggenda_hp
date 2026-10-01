<?php

namespace Tests\Feature\Admin;

use App\Enums\AnalysisKind;
use App\Enums\AnalysisStatus;
use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\Analysis;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼CJ-2(2026-10-01): 無料診断を経由せず、管理者が直接3〜5社比較を
 * 作成する(見込み企業への先行アプローチ用)。既存の起点ありの比較
 * フロー自体はAdminComparisonTest/AdminComparisonWizardTestが引き続き
 * カバーする ―― ここではstandalone経路固有の検証(自社企業名の必須化、
 * 既存企業への一致/新規作成の両方、既存企業情報を上書きしないこと、
 * 同時実行ガードが起点ありの比較と共有されていること)のみを扱う。
 */
class AdminComparisonStandaloneTest extends TestCase
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

    private function validCompetitorUrls(int $count): array
    {
        return array_map(fn (int $i) => "https://competitor{$i}.example.com", range(1, $count));
    }

    private function validCompetitorNames(int $count): array
    {
        return array_map(fn (int $i) => "競合{$i}社", range(1, $count));
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'self_company_name' => 'テスト株式会社',
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
        ], $overrides);
    }

    public function test_it_creates_a_new_lead_company_and_a_comparison_analysis(): void
    {
        Queue::fake([StartAnalysisJob::class]);

        $response = $this->asAdmin()->post('/admin/comparisons/new', $this->validPayload());

        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect();

        $company = LeadCompany::query()->where('company_name', 'テスト株式会社')->first();
        $this->assertNotNull($company);
        // 依頼CJ-2: 担当者情報は収集しない(nullのまま)。
        $this->assertNull($company->primary_contact_name);
        $this->assertNull($company->primary_contact_email);
        $this->assertSame('self.example.com', $company->normalized_domain);

        $analysis = Analysis::query()->where('kind', AnalysisKind::AdminComparison)->firstOrFail();
        $this->assertNull($analysis->source_analysis_id);
        $this->assertSame($company->id, $analysis->project?->lead_company_id);
        $this->assertSame(4, $analysis->project->websites()->count());

        Queue::assertPushed(StartAnalysisJob::class, 1);
    }

    public function test_it_matches_an_existing_company_by_domain_and_does_not_overwrite_its_contact_info(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $existing = LeadCompany::factory()->create([
            'company_name' => '既存会社の正式名称',
            'normalized_domain' => 'self.example.com',
            'primary_contact_name' => '既存担当者',
            'primary_contact_email' => 'existing@example.com',
        ]);

        $response = $this->asAdmin()->post('/admin/comparisons/new', $this->validPayload([
            // 依頼CJ-2: 一致した場合、入力した企業名がドメイン一致を
            // 上書きすることはない(既存のcompany_nameを変えない)。
            'self_company_name' => '違う表記の社名',
        ]));

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(1, LeadCompany::query()->count());

        $existing->refresh();
        $this->assertSame('既存会社の正式名称', $existing->company_name);
        $this->assertSame('既存担当者', $existing->primary_contact_name);
        $this->assertSame('existing@example.com', $existing->primary_contact_email);

        $analysis = Analysis::query()->where('kind', AnalysisKind::AdminComparison)->firstOrFail();
        $this->assertSame($existing->id, $analysis->project?->lead_company_id);
    }

    public function test_it_matches_an_existing_company_by_name_when_the_domain_is_new(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $existing = LeadCompany::factory()->create([
            'company_name' => 'テスト株式会社',
            'normalized_domain' => 'other-domain.example.com',
        ]);

        $this->asAdmin()->post('/admin/comparisons/new', $this->validPayload([
            'self_company_name' => 'テスト株式会社',
            'self_url' => 'https://brand-new-domain.example.com',
        ]))->assertSessionDoesntHaveErrors();

        $this->assertSame(1, LeadCompany::query()->count());
        $analysis = Analysis::query()->where('kind', AnalysisKind::AdminComparison)->firstOrFail();
        $this->assertSame($existing->id, $analysis->project?->lead_company_id);
    }

    public function test_self_company_name_is_required(): void
    {
        Queue::fake([StartAnalysisJob::class]);

        $response = $this->asAdmin()->post('/admin/comparisons/new', $this->validPayload(['self_company_name' => '']));

        $response->assertSessionHasErrors('self_company_name');
        Queue::assertNotPushed(StartAnalysisJob::class);
    }

    public function test_self_url_is_required(): void
    {
        Queue::fake([StartAnalysisJob::class]);

        $response = $this->asAdmin()->post('/admin/comparisons/new', $this->validPayload(['self_url' => '']));

        $response->assertSessionHasErrors('self_url');
        Queue::assertNotPushed(StartAnalysisJob::class);
    }

    public function test_competitor_count_bounds_are_enforced(): void
    {
        Queue::fake([StartAnalysisJob::class]);

        $response = $this->asAdmin()->post('/admin/comparisons/new', $this->validPayload([
            'competitor_urls' => $this->validCompetitorUrls(2),
            'competitor_names' => $this->validCompetitorNames(2),
        ]));

        $response->assertSessionHasErrors('competitor_urls');
        Queue::assertNotPushed(StartAnalysisJob::class);
    }

    /**
     * 依頼AB-3/CJ-2: 管理者起点の比較は、起点あり・起点なしを合わせて
     * 同時に1件まで(同時実行ガードの共有)。
     */
    public function test_it_is_blocked_while_another_comparison_of_either_kind_is_in_progress(): void
    {
        $company = LeadCompany::factory()->create();
        $project = Project::factory()->for(User::factory()->create())->create(['lead_company_id' => $company->id]);
        Analysis::factory()->for($project)->create([
            'kind' => AnalysisKind::AdminComparison,
            'status' => AnalysisStatus::Running,
        ]);

        $response = $this->asAdmin()->post('/admin/comparisons/new', $this->validPayload());

        $response->assertSessionHasErrors('competitor_urls');
    }

    public function test_the_wizard_page_shows_the_standalone_entry_option(): void
    {
        $response = $this->asAdmin()->get('/admin/comparisons/wizard');

        $response->assertOk();
        $response->assertSee('無料診断なしで新しく作る');
    }

    /**
     * 依頼CJ-4(2026-10-01): standalone比較の詳細画面は「起点から作成」
     * リンクを出さず、「無料診断なしで作成されました」の案内を出す。
     */
    public function test_the_detail_page_shows_the_standalone_notice_instead_of_a_source_link(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $this->asAdmin()->post('/admin/comparisons/new', $this->validPayload());
        $analysis = Analysis::query()->where('kind', AnalysisKind::AdminComparison)->firstOrFail();

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee('無料診断を経由せず', false);
        $response->assertDontSee('から作成されました');
    }

    /**
     * 依頼CJ-4: 企業詳細画面のバッジは「比較(単独で作成)」になる
     * (起点ありの「比較(#xから作成)」とは別の表示)。
     */
    public function test_the_company_page_shows_the_standalone_badge(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $this->asAdmin()->post('/admin/comparisons/new', $this->validPayload());
        $company = LeadCompany::query()->firstOrFail();

        $response = $this->asAdmin()->get("/admin/companies/{$company->id}");

        $response->assertOk();
        $response->assertSee('比較(単独で作成)');
    }

    public function test_it_appears_in_the_comparisons_index(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $this->asAdmin()->post('/admin/comparisons/new', $this->validPayload());

        $response = $this->asAdmin()->get('/admin/comparisons');

        $response->assertOk();
        $response->assertSee('テスト株式会社');
    }
}
