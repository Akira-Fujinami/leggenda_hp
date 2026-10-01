<?php

namespace Tests\Feature\Admin;

use App\Enums\AnalysisKind;
use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\User;
use App\Services\Admin\DashboardMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 依頼CJ-3(2026-10-01): 比較(kind=AdminComparison)を無料診断として
 * 数えないことの確認。無料診断を経由しない比較(依頼CJ-2)がLeadCompanyを
 * 作れるようになったことで、既存の集計(company_count/re_diagnosed_count/
 * today_count/month_count/recentCompanies()/notableCompanies())が
 * 比較を診断として混入させていたバグ(agent調査で確認済み、依頼CH以前から
 * 存在していた)が、無料診断を経由しない比較の登場で初めて実害化する。
 */
class DashboardKindAwareCountingTest extends TestCase
{
    use RefreshDatabase;

    private function makeDiagnosis(LeadCompany $company, ?\DateTimeInterface $createdAt = null): Analysis
    {
        $sentinel = User::factory()->create();
        $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);

        return Analysis::factory()->for($project)->create([
            'created_by' => $sentinel->id,
            'kind' => AnalysisKind::LeadDiagnosis,
            'status' => AnalysisStatus::Completed,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    private function makeComparison(LeadCompany $company, ?\DateTimeInterface $createdAt = null): Analysis
    {
        $sentinel = User::factory()->create();
        $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);

        return Analysis::factory()->for($project)->create([
            'created_by' => $sentinel->id,
            'kind' => AnalysisKind::AdminComparison,
            'status' => AnalysisStatus::Completed,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    /**
     * 診断1件+比較1件の企業は、再診断企業(diagnosis_count>=2)ではない。
     * 修正前は合計2件のAnalysisがあるというだけで誤って再診断企業として
     * 数えられていた。
     */
    public function test_a_company_with_one_diagnosis_and_one_comparison_is_not_counted_as_re_diagnosed(): void
    {
        $company = LeadCompany::factory()->create();
        $this->makeDiagnosis($company);
        $this->makeComparison($company);

        $kpis = app(DashboardMetricsService::class)->kpis();

        $this->assertSame(0, $kpis['re_diagnosed_count']);
    }

    public function test_a_company_with_two_diagnoses_is_counted_as_re_diagnosed_even_with_an_extra_comparison(): void
    {
        $company = LeadCompany::factory()->create();
        $this->makeDiagnosis($company);
        $this->makeDiagnosis($company);
        $this->makeComparison($company);

        $kpis = app(DashboardMetricsService::class)->kpis();

        $this->assertSame(1, $kpis['re_diagnosed_count']);
    }

    public function test_today_and_month_counts_exclude_comparisons(): void
    {
        $company = LeadCompany::factory()->create();
        $this->makeDiagnosis($company, now());
        $this->makeComparison($company, now());

        $kpis = app(DashboardMetricsService::class)->kpis();

        $this->assertSame(1, $kpis['today_count']);
        $this->assertSame(1, $kpis['month_count']);
    }

    /**
     * 依頼CJ-2で、無料診断を経由しない比較のみのLeadCompanyが作れるように
     * なった。company_count(診断企業数)はこれを含めず、
     * comparison_only_company_countで別に数える。
     */
    public function test_a_comparison_only_company_is_excluded_from_company_count_and_counted_separately(): void
    {
        $diagnosed = LeadCompany::factory()->create();
        $this->makeDiagnosis($diagnosed);

        $comparisonOnly = LeadCompany::factory()->create();
        $this->makeComparison($comparisonOnly);

        $kpis = app(DashboardMetricsService::class)->kpis();

        $this->assertSame(1, $kpis['company_count']);
        $this->assertSame(1, $kpis['comparison_only_company_count']);
    }

    /**
     * 比較のみだった企業が後から実際に無料診断を受けると、
     * comparison_only_company_countからcompany_countへ移る
     * (保存されたフラグではなく、都度の集計で自然に切り替わることの確認)。
     */
    public function test_a_comparison_only_company_moves_to_company_count_once_a_real_diagnosis_happens(): void
    {
        $company = LeadCompany::factory()->create();
        $this->makeComparison($company);

        $before = app(DashboardMetricsService::class)->kpis();
        $this->assertSame(0, $before['company_count']);
        $this->assertSame(1, $before['comparison_only_company_count']);

        $this->makeDiagnosis($company);

        $after = app(DashboardMetricsService::class)->kpis();
        $this->assertSame(1, $after['company_count']);
        $this->assertSame(0, $after['comparison_only_company_count']);
    }

    /**
     * recentCompanies()/notableCompanies()のdiagnosis_count/last_diagnosed_atは
     * 比較を含めない。
     */
    public function test_recent_companies_diagnosis_count_and_last_diagnosed_at_exclude_comparisons(): void
    {
        $company = LeadCompany::factory()->create();
        $this->makeDiagnosis($company, now()->subDays(2));
        $this->makeComparison($company, now());

        $rows = app(DashboardMetricsService::class)->recentCompanies();
        $row = $rows->firstWhere('company_id', $company->id);

        $this->assertNotNull($row);
        $this->assertSame(1, $row['diagnosis_count']);
        $this->assertTrue($row['last_diagnosed_at']->isSameDay(now()->subDays(2)));
    }

    public function test_notable_companies_excludes_a_company_with_one_diagnosis_and_one_comparison(): void
    {
        $company = LeadCompany::factory()->create();
        $this->makeDiagnosis($company);
        $this->makeComparison($company);

        $rows = app(DashboardMetricsService::class)->notableCompanies();

        $this->assertNull($rows->firstWhere('company_id', $company->id));
    }
}
