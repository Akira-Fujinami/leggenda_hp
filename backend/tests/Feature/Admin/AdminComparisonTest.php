<?php

namespace Tests\Feature\Admin;

use App\Enums\AnalysisStatus;
use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\Analysis;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 依頼AB(2026-08-27): 無料診断を起点に、管理画面から自社+競合3〜5社の
 * 比較を実行する機能。
 */
class AdminComparisonTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): static
    {
        return $this->withSession(['admin_authenticated' => true]);
    }

    /**
     * 無料診断相当のAnalysis(自社1+競合1、企業紐づき)を作る。
     */
    private function makeSourceAnalysis(?string $competitorUrl = 'https://competitor.example.com'): Analysis
    {
        $sentinel = User::factory()->create();
        $company = LeadCompany::factory()->create();
        $project = new Project(['name' => 'テスト']);
        $project->user_id = $sentinel->id;
        $project->lead_company_id = $company->id;
        $project->save();

        Website::factory()->for($project)->create(['url' => 'https://self.example.com', 'normalized_url' => 'https://self.example.com', 'is_primary' => true, 'display_order' => 0]);
        if ($competitorUrl !== null) {
            Website::factory()->for($project)->create(['url' => $competitorUrl, 'normalized_url' => $competitorUrl, 'is_primary' => false, 'display_order' => 1]);
        }

        return Analysis::factory()->for($project)->create(['created_by' => $sentinel->id, 'status' => AnalysisStatus::Completed]);
    }

    private function validCompetitorUrls(int $count): array
    {
        return array_map(fn (int $i) => "https://competitor{$i}.example.com", range(1, $count));
    }

    // ------------------------------------------------------------------
    // AB-1: 起点は無料診断の画面であること。
    // ------------------------------------------------------------------

    public function test_create_form_prefills_self_url_and_the_existing_competitor_url(): void
    {
        $source = $this->makeSourceAnalysis(competitorUrl: 'https://existing-competitor.example.com');

        $response = $this->asAdmin()->get("/admin/analyses/{$source->id}/compare");

        $response->assertOk();
        $response->assertSee('https://self.example.com', false);
        $response->assertSee('https://existing-competitor.example.com', false);
    }

    public function test_competitor_urls_are_rejected_when_below_the_configured_minimum(): void
    {
        config(['analysis.admin_comparison.min_competitors' => 3, 'analysis.admin_comparison.max_competitors' => 5]);
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(2),
        ]);

        $response->assertSessionHasErrors('competitor_urls');
        Queue::assertNotPushed(StartAnalysisJob::class);
    }

    public function test_competitor_urls_are_rejected_when_above_the_configured_maximum(): void
    {
        config(['analysis.admin_comparison.min_competitors' => 3, 'analysis.admin_comparison.max_competitors' => 5]);
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(6),
        ]);

        $response->assertSessionHasErrors('competitor_urls');
        Queue::assertNotPushed(StartAnalysisJob::class);
    }

    public function test_bounds_follow_config_overrides(): void
    {
        config(['analysis.admin_comparison.min_competitors' => 2, 'analysis.admin_comparison.max_competitors' => 2]);
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(2),
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();
    }

    public function test_duplicate_host_among_competitors_is_rejected(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => ['https://a.example.com', 'https://a.example.com/careers', 'https://c.example.com'],
        ]);

        $response->assertSessionHasErrors('competitor_urls');
    }

    public function test_duplicate_host_between_self_and_a_competitor_is_rejected(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => ['https://self.example.com/careers', 'https://b.example.com', 'https://c.example.com'],
        ]);

        $response->assertSessionHasErrors('competitor_urls');
    }

    public function test_invalid_url_format_is_rejected(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            // UrlNormalizer::normalize()がURLの形式エラーとして拒否する
            // (ホスト名が空)、既存のWebsiteService::create()と同じ検証経路。
            'competitor_urls' => ['http://', 'https://b.example.com', 'https://c.example.com'],
        ]);

        $response->assertSessionHasErrors();
    }

    public function test_non_http_scheme_is_rejected(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => ['ftp://a.example.com', 'https://b.example.com', 'https://c.example.com'],
        ]);

        $response->assertSessionHasErrors();
    }

    /**
     * admin.authミドルウェアは、未認証のGETには200(ログインモーダルのみの
     * ビュー)を返し、書き込み系(POST等)は401で拒否する
     * (Tests\Feature\Admin\AdminAuthTestの既存の挙動と同じ)。
     */
    public function test_unauthenticated_access_is_blocked(): void
    {
        $source = $this->makeSourceAnalysis();

        $getResponse = $this->get("/admin/analyses/{$source->id}/compare");
        $getResponse->assertOk();
        $getResponse->assertDontSee('比較を開始する');

        $this->post("/admin/analyses/{$source->id}/compare", ['competitor_urls' => $this->validCompetitorUrls(3)])
            ->assertStatus(401);
        $this->assertSame(0, Analysis::query()->whereNotNull('source_analysis_id')->count());
    }

    // ------------------------------------------------------------------
    // AB-2: 無料診断との紐づけ。
    // ------------------------------------------------------------------

    public function test_comparison_project_gets_the_same_lead_company_id_as_the_source(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
        ])->assertRedirect();

        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();

        $this->assertSame($source->project->lead_company_id, $comparison->project->lead_company_id);
        $this->assertNull($comparison->project->lead_session_id);
        $this->assertNotSame($source->project_id, $comparison->project_id);
    }

    public function test_company_page_shows_both_the_source_diagnosis_and_the_comparison(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();
        $companyId = $source->project->lead_company_id;

        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
        ])->assertRedirect();

        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();

        $response = $this->asAdmin()->get("/admin/companies/{$companyId}");

        $response->assertOk();
        // 診断履歴に両方の行が現れること(詳細リンクのhrefで判別)。
        $response->assertSee(route('admin.analyses.show', $source->id, false), false);
        $response->assertSee(route('admin.analyses.show', $comparison->id, false), false);
        // 比較の行だけ、起点への参照バッジで見分けられること
        // (source_analysis_idの有無、サイト数からの推測はしない)。
        $response->assertSee("比較(#{$source->id}から作成)", false);
    }

    public function test_source_and_comparison_link_to_each_other(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
        ])->assertRedirect();

        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();

        $this->asAdmin()->get("/admin/analyses/{$comparison->id}")
            ->assertOk()
            ->assertSee(route('admin.analyses.show', $source->id, false), false);

        $this->asAdmin()->get("/admin/analyses/{$source->id}")
            ->assertOk()
            ->assertSee(route('admin.analyses.show', $comparison->id, false), false);
    }

    public function test_comparison_survives_deletion_of_the_source_analysis(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
        ])->assertRedirect();

        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();
        $source->delete();

        $comparison->refresh();
        $this->assertNull($comparison->source_analysis_id);
    }

    public function test_cannot_start_a_comparison_from_a_comparison(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
        ])->assertRedirect();

        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();

        $this->asAdmin()->get("/admin/analyses/{$comparison->id}/compare")->assertNotFound();
    }

    // ------------------------------------------------------------------
    // AB-3: パイプラインをN社で回す。
    // ------------------------------------------------------------------

    public function test_it_creates_six_website_analyses_preserving_display_order(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();
        $competitorUrls = $this->validCompetitorUrls(5);

        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $competitorUrls,
        ])->assertRedirect();

        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();
        $comparison->load('websiteAnalyses.website', 'project.websites');

        $this->assertCount(6, $comparison->websiteAnalyses);
        $websites = $comparison->project->websites; // orderBy('display_order') per Project::websites()
        $this->assertTrue((bool) $websites->first()->is_primary);
        $this->assertSame(
            $competitorUrls,
            $websites->where('is_primary', false)->pluck('url')->values()->all(),
        );
    }

    // ------------------------------------------------------------------
    // 依頼AC-2: 比較レポートの列見出しに実際の社名を使うため、Websiteの名前
    // (フォームの企業名入力、空欄時はURLドメインから自動生成)。
    // ------------------------------------------------------------------

    public function test_competitor_website_names_use_the_admin_provided_names_when_given(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => ['サイボウズ', '', 'ZOZO'],
        ])->assertRedirect();

        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();
        $names = $comparison->project->websites()->where('is_primary', false)->orderBy('display_order')->pluck('name')->all();

        $this->assertSame('サイボウズ', $names[0]);
        // 2件目は空欄のため、URLドメインから自動生成される
        // (competitor2.example.com → competitor2.example.com、www無しのため
        // そのまま)。
        $this->assertSame('competitor2.example.com', $names[1]);
        $this->assertSame('ZOZO', $names[2]);
    }

    public function test_competitor_website_name_falls_back_to_the_url_domain_when_no_name_is_given(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => ['https://www.cybozu.example.com', 'https://competitor2.example.com', 'https://competitor3.example.com'],
        ])->assertRedirect();

        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();
        $names = $comparison->project->websites()->where('is_primary', false)->orderBy('display_order')->pluck('name')->all();

        // www.は既存のLeadCompanyResolver::extractDomain()と同じくstripWww()
        // で除去される。
        $this->assertSame('cybozu.example.com', $names[0]);
    }

    public function test_lead_quota_is_not_consumed(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
        ])->assertRedirect();

        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();
        $this->assertNull($comparison->lead_quota_consumed_at);
        $this->assertNull($comparison->project->lead_session_id);
    }

    public function test_a_second_comparison_cannot_be_started_while_one_is_in_progress(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source1 = $this->makeSourceAnalysis();
        $source2 = $this->makeSourceAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$source1->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
        ])->assertRedirect();

        $response = $this->asAdmin()->post("/admin/analyses/{$source2->id}/compare", [
            'self_url' => 'https://self2.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
        ]);

        $response->assertSessionHasErrors('competitor_urls');
        $this->assertSame(1, Analysis::query()->whereNotNull('source_analysis_id')->count());
    }
}
