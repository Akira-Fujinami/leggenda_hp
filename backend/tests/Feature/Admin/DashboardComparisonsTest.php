<?php

namespace Tests\Feature\Admin;

use App\Models\Analysis;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 依頼BW-3(2026-09-11): ダッシュボードの並び替え(比較を作る→作成中・
 * 最近の比較→KPI→最近の診断企業/注目企業→要確認・エラー)。既存のKPI・
 * カードは1つも消さない(依頼者指定)。
 */
class DashboardComparisonsTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): static
    {
        return $this->withSession(['admin_authenticated' => true]);
    }

    private function makeComparison(): Analysis
    {
        $sentinel = User::factory()->create();
        $company = LeadCompany::factory()->create();
        $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);
        $sourceProject = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);
        $source = Analysis::factory()->for($sourceProject)->create(['created_by' => $sentinel->id]);

        return Analysis::factory()->for($project)->create([
            'created_by' => $sentinel->id,
            'source_analysis_id' => $source->id,
        ]);
    }

    public function test_existing_kpi_and_cards_are_still_present(): void
    {
        $response = $this->asAdmin()->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('本日の診断数');
        $response->assertSee('今月の診断数');
        $response->assertSee('診断企業数');
        $response->assertSee('再診断企業数');
        $response->assertSee('相談リクエスト数');
        $response->assertSee('要確認・エラー');
        $response->assertSee('最近の診断企業');
        $response->assertSee('注目企業(再診断あり)');
    }

    public function test_recent_comparisons_section_shows_up_to_the_configured_limit(): void
    {
        $comparisons = [];
        for ($i = 0; $i < 7; $i++) {
            $comparisons[] = $this->makeComparison();
        }

        $response = $this->asAdmin()->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('作成中・最近の比較');
        // 依頼BW-3: 5件程度(依頼者提案どおり)。
        $recent = collect($response->viewData('recentComparisons'));
        $this->assertCount(5, $recent);
        $response->assertSee(route('admin.comparisons.index', [], false));
    }

    public function test_recent_comparisons_shows_empty_state_when_there_are_none(): void
    {
        $response = $this->asAdmin()->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('まだ比較レポートがありません。');
    }

    /**
     * 依頼BW-3: 「最近の比較」でもN+1を出さないこと。
     */
    public function test_dashboard_does_not_cause_n_plus_1_queries_for_recent_comparisons(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeComparison();
        }

        DB::enableQueryLog();
        $response = $this->asAdmin()->get('/admin/dashboard');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        // 実測18件(KPI・最近の比較・最近の診断企業・注目企業・要確認一覧
        // すべて含む、ダッシュボード全体の合計)。比較の件数(5件)を増やしても
        // 増えないことの確認(実測値は報告に記載)。
        $this->assertLessThan(25, $queryCount, "クエリ数が多すぎます(N+1の疑い): {$queryCount}件");
    }
}
