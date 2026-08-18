<?php

namespace Tests\Feature\Admin;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\User;
use App\Services\Admin\DashboardMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ダッシュボードKPI集計(依頼#3・#4・#28)。
 */
class DashboardKpiTest extends TestCase
{
    use RefreshDatabase;

    private function makeLeadAnalysis(?\DateTimeInterface $createdAt = null, string $status = 'completed'): Analysis
    {
        $company = LeadCompany::factory()->create();
        $sentinel = User::factory()->create();
        $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);

        return Analysis::factory()->for($project)->create([
            'created_by' => $sentinel->id,
            'status' => AnalysisStatus::from($status),
            'created_at' => $createdAt ?? now(),
        ]);
    }

    public function test_today_count_only_counts_analyses_created_today(): void
    {
        $this->makeLeadAnalysis(now());
        $this->makeLeadAnalysis(now());
        $this->makeLeadAnalysis(now()->subDays(2));

        $kpis = app(DashboardMetricsService::class)->kpis();

        $this->assertSame(2, $kpis['today_count']);
    }

    public function test_month_count_only_counts_analyses_created_this_month(): void
    {
        $this->makeLeadAnalysis(now());
        $this->makeLeadAnalysis(now()->subMonthsNoOverflow(2));

        $kpis = app(DashboardMetricsService::class)->kpis();

        $this->assertSame(1, $kpis['month_count']);
    }

    public function test_company_count_ignores_projects_created_by_regular_users(): void
    {
        // 社内ユーザーが直接作成したproject(lead_company_idなし)はKPIに含めない。
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['lead_company_id' => null]);
        Analysis::factory()->for($project)->create(['created_by' => $user->id]);

        $this->makeLeadAnalysis(now());

        $kpis = app(DashboardMetricsService::class)->kpis();

        $this->assertSame(1, $kpis['today_count']);
        $this->assertSame(1, $kpis['company_count']);
    }

    public function test_needs_attention_count_includes_failed_and_partial_lead_analyses(): void
    {
        $this->makeLeadAnalysis(now(), 'failed');
        $this->makeLeadAnalysis(now(), 'partial');
        $this->makeLeadAnalysis(now(), 'completed');

        $kpis = app(DashboardMetricsService::class)->kpis();

        $this->assertSame(2, $kpis['needs_attention_count']);
    }

    public function test_needs_attention_excludes_issues_outside_the_recent_window(): void
    {
        $this->makeLeadAnalysis(now()->subDays(60), 'failed');

        $kpis = app(DashboardMetricsService::class)->kpis();

        $this->assertSame(0, $kpis['needs_attention_count']);
    }
}
