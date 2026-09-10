<?php

namespace Tests\Feature\Admin;

use App\Enums\AnalysisStatus;
use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\Analysis;
use App\Models\AnalysisAttachment;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTestPptxDecks;
use Tests\TestCase;

/**
 * 依頼BP(2026-09-10): 会社名から起点の診断を探して選ぶ、チャット風の
 * 比較ウィザード(admin.comparisons.wizard)と、その検索の口
 * (admin.comparisons.search)。
 *
 * 送信の最終経路(POST /admin/analyses/{analysis}/compare)自体の検証は
 * AdminComparisonTestが既に広くカバーしている ―― ここでは「ウィザード
 * 経由でも同じ経路・同じ結果になること」と、ウィザード固有の要素
 * (検索・入口・JS不通時の退路・入力保持)だけを検証する。
 */
class AdminComparisonWizardTest extends TestCase
{
    use RefreshDatabase;
    use MakesTestPptxDecks;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
    }

    private function asAdmin(): static
    {
        return $this->withSession(['admin_authenticated' => true]);
    }

    private function pptxUpload(string $filename, array $slideTexts, int $cx = 12192000, int $cy = 6858000): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($filename, $this->makeMinimalPptxBytes($slideTexts, $cx, $cy));
    }

    private function validCompetitorUrls(int $count): array
    {
        return array_map(fn (int $i) => "https://competitor{$i}.example.com", range(1, $count));
    }

    private function validCompetitorNames(int $count): array
    {
        return array_map(fn (int $i) => "競合{$i}社", range(1, $count));
    }

    /**
     * @param  array{company_name?: string, source_analysis_id?: bool, lead_company_id?: bool, status?: AnalysisStatus, self_url?: string}  $overrides
     */
    private function makeSearchableAnalysis(array $overrides = []): Analysis
    {
        $sentinel = User::factory()->create();
        $company = LeadCompany::factory()->create(['company_name' => $overrides['company_name'] ?? 'テスト株式会社']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = $sentinel->id;
        $project->lead_company_id = ($overrides['lead_company_id'] ?? true) ? $company->id : null;
        $project->save();

        Website::factory()->for($project)->create([
            'url' => $overrides['self_url'] ?? 'https://recruit.example.com',
            'normalized_url' => $overrides['self_url'] ?? 'https://recruit.example.com',
            'is_primary' => true,
            'display_order' => 0,
        ]);

        $analysis = Analysis::factory()->for($project)->create([
            'created_by' => $sentinel->id,
            'status' => $overrides['status'] ?? AnalysisStatus::Completed,
        ]);

        if ($overrides['source_analysis_id'] ?? false) {
            // この起点のダミーは検索対象の会社名と紐づけない(同じ企業名で
            // 別のヒット可能な候補ができてしまい、除外のテストが汚染される
            // ため) ―― ここではsource_analysis_idを非nullにするためだけの
            // 存在で足りる。
            $sourceProject = new Project(['name' => 'テスト元']);
            $sourceProject->user_id = $sentinel->id;
            $sourceProject->save();
            $sourceAnalysis = Analysis::factory()->for($sourceProject)->create(['created_by' => $sentinel->id]);

            $analysis->source_analysis_id = $sourceAnalysis->id;
            $analysis->save();
        }

        return $analysis;
    }

    // ------------------------------------------------------------------
    // 入口(BP-4)。
    // ------------------------------------------------------------------

    public function test_dashboard_links_to_the_wizard(): void
    {
        $response = $this->asAdmin()->get('/admin');

        $response->assertOk();
        $response->assertSee(route('admin.comparisons.wizard', [], false), false);
    }

    /**
     * サイドバー(admin/layout.blade.php)はどの画面でも常時表示されるため、
     * 承認外の admin/analyses/index.blade.php を変更しなくても、診断一覧
     * からウィザードへ2クリック以内で辿れる(依頼BP-4「診断一覧からも
     * 辿れるようにするか判断して提案」への回答)。
     */
    public function test_the_wizard_link_is_reachable_from_the_analyses_index_via_the_shared_sidebar(): void
    {
        $response = $this->asAdmin()->get('/admin/analyses');

        $response->assertOk();
        $response->assertSee(route('admin.comparisons.wizard', [], false), false);
    }

    public function test_wizard_page_keeps_a_fallback_link_to_the_per_diagnosis_compare_page_for_when_js_is_unavailable(): void
    {
        $response = $this->asAdmin()->get('/admin/comparisons/wizard');

        $response->assertOk();
        // JSが動かない環境の退路: 診断一覧 → 診断詳細 →
        // 既存の/admin/analyses/{id}/compare、という導線が必ず残っていること。
        $response->assertSee(route('admin.analyses.index', [], false), false);
        $response->assertSee('3〜5社で比較する');
    }

    public function test_wizard_page_shows_the_empty_result_hint_markup_for_when_search_finds_nothing(): void
    {
        $response = $this->asAdmin()->get('/admin/comparisons/wizard');

        $response->assertOk();
        $response->assertSee('見つかりませんでした');
        $response->assertSee(route('admin.analyses.index', [], false), false);
    }

    public function test_unauthenticated_get_to_the_wizard_returns_the_guest_view_not_the_wizard(): void
    {
        $response = $this->get('/admin/comparisons/wizard');

        $response->assertOk();
        $response->assertDontSee('比較レポートを作る');
    }

    // ------------------------------------------------------------------
    // 検索(BP-2)。
    // ------------------------------------------------------------------

    public function test_search_finds_by_a_partial_company_name(): void
    {
        $this->makeSearchableAnalysis(['company_name' => '株式会社マネーフォワード']);

        $response = $this->asAdmin()->getJson('/admin/comparisons/search?q=マネーフォワード');

        $response->assertOk();
        $response->assertJsonCount(1, 'results');
        $response->assertJsonPath('results.0.company_name', '株式会社マネーフォワード');
    }

    public function test_search_excludes_analyses_that_are_themselves_comparisons(): void
    {
        $this->makeSearchableAnalysis(['company_name' => 'マネーフォワード比較', 'source_analysis_id' => true]);

        $response = $this->asAdmin()->getJson('/admin/comparisons/search?q=マネーフォワード');

        $response->assertOk();
        $response->assertJsonCount(0, 'results');
    }

    public function test_search_excludes_analyses_without_a_lead_company(): void
    {
        $this->makeSearchableAnalysis(['company_name' => 'マネーフォワード内部', 'lead_company_id' => false]);

        $response = $this->asAdmin()->getJson('/admin/comparisons/search?q=マネーフォワード');

        $response->assertOk();
        $response->assertJsonCount(0, 'results');
    }

    public function test_search_response_contains_only_the_allowed_fields_and_no_pii(): void
    {
        LeadCompany::factory()->create([
            'company_name' => 'マネーフォワードPII確認社',
            'primary_contact_name' => '担当太郎',
            'primary_contact_email' => 'tantou@example.com',
        ]);
        $company = LeadCompany::query()->where('company_name', 'マネーフォワードPII確認社')->firstOrFail();
        $sentinel = User::factory()->create();
        $project = new Project(['name' => 'テスト']);
        $project->user_id = $sentinel->id;
        $project->lead_company_id = $company->id;
        $project->save();
        Website::factory()->for($project)->create(['url' => 'https://recruit.moneyforward.com', 'normalized_url' => 'https://recruit.moneyforward.com', 'is_primary' => true, 'display_order' => 0]);
        Analysis::factory()->for($project)->create(['created_by' => $sentinel->id, 'status' => AnalysisStatus::Completed]);

        $response = $this->asAdmin()->getJson('/admin/comparisons/search?q=マネーフォワードPII確認社');

        $response->assertOk();
        $response->assertJsonStructure(['results' => [['id', 'company_name', 'self_host', 'analyzed_at', 'status']], 'truncated']);
        $body = $response->json();
        $this->assertCount(5, $body['results'][0], '返すフィールドはid/company_name/self_host/analyzed_at/statusの5つだけであること');
        $raw = $response->getContent();
        $this->assertStringNotContainsString('担当太郎', $raw);
        $this->assertStringNotContainsString('tantou@example.com', $raw);
        $this->assertSame('recruit.moneyforward.com', $body['results'][0]['self_host']);
    }

    public function test_search_is_case_insensitive(): void
    {
        $this->makeSearchableAnalysis(['company_name' => 'SmartHR株式会社']);

        $response = $this->asAdmin()->getJson('/admin/comparisons/search?q=smarthr');

        $response->assertOk();
        $response->assertJsonCount(1, 'results');
    }

    /**
     * 依頼BP-2: 全角・半角の違いを吸収する(検索文字列を正規化する側)。
     * 半角カタカナで検索しても、全角で登録された会社名に一致すること。
     */
    public function test_search_matches_full_width_company_names_when_the_query_is_half_width_katakana(): void
    {
        $this->makeSearchableAnalysis(['company_name' => '株式会社マネーフォワード']);

        $response = $this->asAdmin()->getJson('/admin/comparisons/search?q='.rawurlencode('ﾏﾈｰﾌｫﾜｰﾄﾞ'));

        $response->assertOk();
        $response->assertJsonCount(1, 'results');
    }

    public function test_search_returns_an_empty_result_when_nothing_matches(): void
    {
        $response = $this->asAdmin()->getJson('/admin/comparisons/search?q=該当しない会社名');

        $response->assertOk();
        $response->assertJson(['results' => [], 'truncated' => false]);
    }

    public function test_search_reports_truncation_and_returns_no_candidates_when_over_the_limit(): void
    {
        config(['analysis.admin_comparison.search_result_limit' => 2]);
        $this->makeSearchableAnalysis(['company_name' => 'マネーフォワード業務']);
        $this->makeSearchableAnalysis(['company_name' => 'マネーフォワード経理']);
        $this->makeSearchableAnalysis(['company_name' => 'マネーフォワードクラウド']);

        $response = $this->asAdmin()->getJson('/admin/comparisons/search?q=マネーフォワード');

        $response->assertOk();
        $response->assertJson(['results' => [], 'truncated' => true]);
    }

    public function test_search_requires_authentication(): void
    {
        $response = $this->getJson('/admin/comparisons/search?q=test');

        $response->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // 送信(BP-3): 既存の POST /admin/analyses/{analysis}/compare をそのまま
    // 使うこと。
    // ------------------------------------------------------------------

    public function test_a_wizard_shaped_submission_reaches_the_existing_store_endpoint_and_creates_the_same_kind_of_comparison(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSearchableAnalysis(['company_name' => '株式会社ウィザードテスト']);

        // ウィザードがJSで組み立てる<form>と同じ形(self_urlは送らない、
        // company_query/source_analysis_idはstore()側で無視される付随情報)。
        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'company_query' => 'ウィザードテスト',
            'source_analysis_id' => (string) $source->id,
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
        ]);

        $response->assertRedirect(route('admin.analyses.show', ['analysis' => Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail()->id], false));
        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();
        $this->assertSame($source->id, $comparison->source_analysis_id);
        $this->assertSame($source->project->lead_company_id, $comparison->project->lead_company_id);
        // self_urlを送らなかった場合、起点の診断の自社URLが使われること
        // (AdminComparisonService::createFromSourceAnalysis()の既存挙動)。
        $this->assertTrue($comparison->project->websites()->where('is_primary', true)->where('url', 'https://recruit.example.com')->exists());
    }

    public function test_wizard_submission_without_a_sales_deck_creates_a_comparison_with_no_attachment(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSearchableAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'source_analysis_id' => (string) $source->id,
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
        ])->assertRedirect();

        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();
        $this->assertSame(0, AnalysisAttachment::where('analysis_id', $comparison->id)->count());
    }

    public function test_a_pptx_without_a_reference_page_is_rejected_and_no_comparison_is_created(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSearchableAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'source_analysis_id' => (string) $source->id,
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
            'sales_deck' => $this->pptxUpload('営業資料.pptx', ['内容1', '内容2']),
        ]);

        $response->assertSessionHasErrors('sales_deck');
        $this->assertSame(0, Analysis::query()->whereNotNull('source_analysis_id')->count());
    }

    public function test_empty_competitor_name_is_rejected_and_the_selection_is_preserved_on_the_wizard_page(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSearchableAnalysis(['company_name' => '株式会社入力保持テスト']);

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'company_query' => '入力保持テスト',
            'source_analysis_id' => (string) $source->id,
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => ['競合1社', '', '競合3社'],
        ]);

        $response->assertSessionHasErrors('competitor_names.1');
        $this->assertSame(0, Analysis::query()->whereNotNull('source_analysis_id')->count());

        // STEP1〜3の入力(検索した会社・選んだ診断・競合URL/企業名)が
        // ウィザードの再表示で失われていないこと(依頼者指定)。
        $wizardPage = $this->asAdmin()->get('/admin/comparisons/wizard');
        $wizardPage->assertOk();
        $wizardPage->assertSee('株式会社入力保持テスト');
        $wizardPage->assertSee($this->validCompetitorUrls(3)[0], false);
        $wizardPage->assertSee('競合1社');
    }

    public function test_sales_deck_error_is_recorded_in_the_session_for_the_wizard_page_to_show_the_refile_notice(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSearchableAnalysis();

        // $errors(ViewErrorBagのセッションフラッシュ)は、この環境のPHPUnit
        // テストではPOST→別のGETという2ホップの経路で再現よく検証できない
        // (old()側のフラッシュ(_old_input)は再現するため、直前の
        // test_empty_competitor_name_is_rejected_...で検証済み) ―― 既存の
        // comparisons/create.blade.phpの同種のrefile-note文言も、同じ理由で
        // 既存テストに1件もカバーが無い(grep確認済み)。ここでは、
        // POSTした結果セッションに実際にsales_deckのエラーが乗ること
        // (既存コードベースの慣例どおりassertSessionHasErrors()で検証、
        // AdminComparisonTestの多数のテストと同じパターン)と、ビュー自体が
        // $errorsを受け取ったときに正しく文言を出すこと(HTTPを介さず
        // view()を直接レンダリングして検証、セッションのフラッシュ挙動に
        // 依存しない)の2つに分けて確認する。
        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'source_analysis_id' => (string) $source->id,
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
            'sales_deck' => $this->pptxUpload('営業資料.pptx', ['内容1', '内容2']),
        ])->assertSessionHasErrors('sales_deck');

        $rendered = view('admin.comparisons.wizard', [
            'selectedAnalysis' => null,
            'minCompetitors' => (int) config('analysis.admin_comparison.min_competitors', 3),
            'maxCompetitors' => (int) config('analysis.admin_comparison.max_competitors', 5),
            'salesDeckMaxSizeMb' => 20,
            'salesDeckSlideSizeLabel' => '33.87 × 19.05 cm',
            'salesDeckReferenceKeywords' => ['参照元', 'APPENDIX'],
            'errors' => (new \Illuminate\Support\ViewErrorBag)->put(
                'default',
                new \Illuminate\Support\MessageBag(['sales_deck' => ['営業資料に「参照元」のページが見つかりませんでした。']]),
            ),
        ])->render();

        $this->assertStringContainsString('もう一度ファイルを選び直してください', $rendered);
    }
}
