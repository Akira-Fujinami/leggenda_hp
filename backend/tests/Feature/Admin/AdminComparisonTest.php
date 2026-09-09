<?php

namespace Tests\Feature\Admin;

use App\Enums\AnalysisStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportGenerationStatus;
use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\Analysis;
use App\Models\AnalysisAttachment;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTestPptxDecks;
use Tests\TestCase;

/**
 * 依頼AB(2026-08-27): 無料診断を起点に、管理画面から自社+競合3〜5社の
 * 比較を実行する機能。
 *
 * 依頼BI(2026-09-08): 起票フォームへの営業資料(PPTX)添付・送信時点での
 * 検証前倒し。
 */
class AdminComparisonTest extends TestCase
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

    /**
     * show.blade.phpの「営業資料に差し込む」導線・添付ガイダンス文言は、
     * 既存の比較PDFレポート行(format=pdf, status=completed)の中に並べて
     * 出すため、画面表示系のテストではこの行を用意する必要がある
     * (AdminComparisonPptxInsertTestと同じパターン)。
     */
    private function makeCompletedPdfReport(Analysis $analysis): Report
    {
        $path = "reports/{$analysis->id}/admin-comparison-report.pdf";
        Storage::disk('analysis')->put($path, 'pdf-bytes');

        return Report::factory()->create([
            'analysis_id' => $analysis->id,
            'format' => ReportFormat::Pdf->value,
            'storage_path' => $path,
            'status' => ReportGenerationStatus::Completed->value,
            'generated_at' => now(),
        ]);
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

    /**
     * 依頼BM-3: URLを入力した行は企業名も必須になったため、
     * validCompetitorUrls()と組で使う既定の企業名を用意する。
     */
    private function validCompetitorNames(int $count): array
    {
        return array_map(fn (int $i) => "競合{$i}社", range(1, $count));
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
            'competitor_names' => $this->validCompetitorNames(2),
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
            'competitor_names' => $this->validCompetitorNames(6),
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
            'competitor_names' => $this->validCompetitorNames(2),
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
            'competitor_names' => $this->validCompetitorNames(3),
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
            'competitor_names' => $this->validCompetitorNames(3),
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
            'competitor_names' => $this->validCompetitorNames(3),
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
            'competitor_names' => $this->validCompetitorNames(3),
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
            'competitor_names' => $this->validCompetitorNames(3),
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
            'competitor_names' => $this->validCompetitorNames(3),
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
            'competitor_names' => $this->validCompetitorNames(3),
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
            'competitor_names' => $this->validCompetitorNames(3),
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
            'competitor_names' => $this->validCompetitorNames(3),
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
            'competitor_names' => $this->validCompetitorNames(5),
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
            'competitor_names' => ['サイボウズ', 'フリー', 'ZOZO'],
        ])->assertRedirect();

        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();
        $names = $comparison->project->websites()->where('is_primary', false)->orderBy('display_order')->pluck('name')->all();

        $this->assertSame(['サイボウズ', 'フリー', 'ZOZO'], $names);
    }

    /**
     * 依頼BM-3: URLを入力した行は、企業名も必須にする(空欄だとホスト名の
     * 自動生成に頼ることになり、比較レポート・営業資料差し込み用スライドの
     * 列見出しが読めない表記になっていたため、依頼者指摘)。フォーム経由の
     * 送信ではこの検証で弾かれ、比較は作られないこと。
     */
    public function test_a_competitor_url_without_a_name_is_rejected_by_the_form(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => ['https://competitor1.example.com', 'https://competitor2.example.com', 'https://competitor3.example.com'],
            'competitor_names' => ['サイボウズ', '', 'ZOZO'],
        ]);

        $response->assertSessionHasErrors('competitor_names.1');
        $response->assertSessionHas('_old_input.competitor_urls.0', 'https://competitor1.example.com');
        $this->assertSame(0, Analysis::query()->whereNotNull('source_analysis_id')->count());
    }

    /**
     * 依頼BM-3: 自動生成のフォールバック自体は残す(既存データが壊れる
     * ため、依頼者指定)。ただしホスト名をそのまま使わず、
     * AdminComparisonService::shortenDomainLabel()で短縮する
     * (hello-world.smarthr.co.jp → smarthr 程度)。フォーム側は空欄の
     * 企業名を弾くため、このフォールバックは主にサービスを直接呼ぶ経路
     * (既存データ・将来の別呼び出し元)向けの安全網として、サービスを
     * 直接呼んで検証する。
     */
    public function test_the_auto_generated_fallback_name_shortens_the_domain_when_the_service_is_called_directly(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $comparison = app(\App\Services\Admin\AdminComparisonService::class)->createFromSourceAnalysis(
            $source,
            'https://self.example.com',
            ['https://jobs.brandco.co.jp', 'https://cybozu.jp', 'https://sub.example-corp.com'],
            [],
        );

        $names = $comparison->project->websites()->where('is_primary', false)->orderBy('display_order')->pluck('name')->all();

        // jobs.brandco.co.jp → co.jpは日本語ドメインでよく使う2階層
        // サフィックスのため、その手前の1ラベル(brandco)まで短縮される。
        $this->assertSame('brandco', $names[0]);
        // cybozu.jpは元々2ラベルのため、そのまま(依頼者指定の例
        // 「cybozu.co.jp → cybozu、元々短いものはそのまま」と同じ考え方)。
        $this->assertSame('cybozu', $names[1]);
        // sub.example-corp.comは3ラベルでcom単体(2階層サフィックス表に
        // 無い)ため、末尾から2ラベル目(example-corp、先頭のsubを落とす)。
        $this->assertSame('example-corp', $names[2]);
    }

    public function test_lead_quota_is_not_consumed(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
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
            'competitor_names' => $this->validCompetitorNames(3),
        ])->assertRedirect();

        $response = $this->asAdmin()->post("/admin/analyses/{$source2->id}/compare", [
            'self_url' => 'https://self2.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
        ]);

        $response->assertSessionHasErrors('competitor_urls');
        $this->assertSame(1, Analysis::query()->whereNotNull('source_analysis_id')->count());
    }

    // ------------------------------------------------------------------
    // 依頼BI-1/BI-2/BI-3: 3ステップ起票フォームへの営業資料(PPTX)添付と、
    // 送信時点での検証前倒し。
    // ------------------------------------------------------------------

    public function test_the_form_has_multipart_enctype_and_the_uploaded_file_actually_reaches_the_server(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $formResponse = $this->asAdmin()->get("/admin/analyses/{$source->id}/compare");
        $formResponse->assertOk();
        $formResponse->assertSee('enctype="multipart/form-data"', false);

        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
            'sales_deck' => $this->pptxUpload('営業資料.pptx', ['内容1', '参照元']),
        ])->assertRedirect();

        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();
        $this->assertSame(1, AnalysisAttachment::where('analysis_id', $comparison->id)->where('extension', 'pptx')->count());
    }

    public function test_submitting_without_a_sales_deck_creates_the_comparison_with_no_attachment(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
        ])->assertRedirect();

        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();
        $this->assertSame(0, AnalysisAttachment::where('analysis_id', $comparison->id)->count());
    }

    public function test_submitting_with_a_valid_pptx_links_the_attachment_to_the_new_comparison_not_the_source(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
            'sales_deck' => $this->pptxUpload('営業資料.pptx', ['内容1', '参照元']),
        ])->assertRedirect();

        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();
        $this->assertSame(1, AnalysisAttachment::where('analysis_id', $comparison->id)->where('extension', 'pptx')->count());
        // 依頼BI-2: 添付先は「新しく作られた比較Analysis」であり、起点の
        // 診断(source)には一切紐づかないこと。
        $this->assertSame(0, AnalysisAttachment::where('analysis_id', $source->id)->count());
    }

    /**
     * 依頼BK: 比較作成フォームでも、許容差(既定1200EMU)以内のずれは
     * 弾かれないこと。詳細画面の添付欄・ダウンロード時と同じ判定になる
     * こと(3経路すべての確認、依頼BK指定)。
     */
    public function test_a_pptx_1200_emu_off_attaches_and_submits_successfully(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
            'sales_deck' => $this->pptxUpload('営業資料.pptx', ['内容1', '参照元'], 12192000 - 1200, 6858000 - 1200),
        ]);

        $response->assertSessionDoesntHaveErrors('sales_deck');
        $response->assertRedirect();
        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();
        $this->assertSame(1, AnalysisAttachment::where('analysis_id', $comparison->id)->where('extension', 'pptx')->count());
    }

    public function test_below_minimum_competitor_urls_with_a_file_attached_does_not_create_a_comparison_and_preserves_input(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(2),
            'competitor_names' => $this->validCompetitorNames(2),
            'sales_deck' => $this->pptxUpload('営業資料.pptx', ['内容1', '参照元']),
        ]);

        $response->assertSessionHasErrors('competitor_urls');
        $response->assertSessionHas('_old_input.self_url', 'https://self.example.com');
        $this->assertSame(0, Analysis::query()->whereNotNull('source_analysis_id')->count());
        $this->assertSame(0, AnalysisAttachment::query()->count());
    }

    public function test_required_and_optional_labels_do_not_disappear_after_a_failed_submission(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        config(['analysis.admin_comparison.min_competitors' => 3, 'analysis.admin_comparison.max_competitors' => 5]);
        $source = $this->makeSourceAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(2),
            'competitor_names' => $this->validCompetitorNames(2),
        ])->assertSessionHasErrors('competitor_urls');

        // ラベルはplaceholderではなく固定のタグ+見出しのため、old()で入力が
        // 復元されても消えないこと(依頼BI-1指定)。
        $response = $this->asAdmin()->get("/admin/analyses/{$source->id}/compare");
        $response->assertOk();
        $response->assertSee('必須');
        $response->assertSee('任意');
        $response->assertSee('競合1');
        $response->assertSee('競合5');
        $response->assertSee('https://competitor1.example.com', false);
    }

    public function test_a_pptx_without_a_reference_page_is_rejected_when_creating_a_comparison(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
            'sales_deck' => $this->pptxUpload('営業資料.pptx', ['内容1', '内容2']),
        ]);

        $response->assertSessionHasErrors('sales_deck');
        $this->assertStringContainsString('参照元', session('errors')->get('sales_deck')[0]);
        $response->assertSessionHas('_old_input.self_url', 'https://self.example.com');
        $this->assertSame(0, Analysis::query()->whereNotNull('source_analysis_id')->count());
    }

    public function test_a_4_3_pptx_is_rejected_with_the_actual_dimensions_when_creating_a_comparison(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
            'sales_deck' => $this->pptxUpload('営業資料.pptx', ['内容1', '参照元'], 9144000, 6858000),
        ]);

        $response->assertSessionHasErrors('sales_deck');
        $message = session('errors')->get('sales_deck')[0];
        $this->assertStringContainsString('4:3', $message);
        $this->assertStringContainsString('25.40', $message);
        $this->assertSame(0, Analysis::query()->whereNotNull('source_analysis_id')->count());
    }

    public function test_pdf_is_rejected_by_this_form_even_though_the_detail_screen_attachment_box_allows_it(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
            'sales_deck' => UploadedFile::fake()->create('資料.pdf', 100, 'application/pdf'),
        ]);

        $response->assertSessionHasErrors('sales_deck');
        $this->assertStringContainsString('pptx', session('errors')->get('sales_deck')[0]);
        $this->assertSame(0, Analysis::query()->whereNotNull('source_analysis_id')->count());
    }

    public function test_docx_is_rejected_by_this_form(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
            'sales_deck' => UploadedFile::fake()->create('資料.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ]);

        $response->assertSessionHasErrors('sales_deck');
        $this->assertSame(0, Analysis::query()->whereNotNull('source_analysis_id')->count());
    }

    /**
     * 依頼BI-4: 実物に近いサイズ(post_max_size/client_max_body_sizeの
     * 引き上げが問題になる境界)のPPTXでも、アプリのロジック上は問題なく
     * 検証・添付できること。post_max_size/client_max_body_size自体は
     * PHP内蔵サーバー(php artisan serve)への実リクエストでのみ再現できる
     * ため、この自動テストでは検証しない(報告時に別途、実リクエストで確認)。
     */
    public function test_a_near_20mb_pptx_attaches_and_submits_successfully(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $path = tempnam(sys_get_temp_dir(), 'near-limit').'.pptx';
        file_put_contents($path, $this->makeMinimalPptxBytes(['内容1', '参照元']));
        $zip = new \ZipArchive();
        $zip->open($path);
        $padBytes = max(0, (19 * 1024 * 1024) - filesize($path));
        $zip->addFromString('ppt/media/pad.bin', random_bytes($padBytes));
        $zip->setCompressionName('ppt/media/pad.bin', \ZipArchive::CM_STORE);
        $zip->close();

        $upload = new UploadedFile($path, 'near20mb.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', null, true);

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
            'sales_deck' => $upload,
        ]);

        $response->assertSessionDoesntHaveErrors('sales_deck');
        $response->assertRedirect();
        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();
        $this->assertSame(1, AnalysisAttachment::where('analysis_id', $comparison->id)->where('extension', 'pptx')->count());

        @unlink($path);
    }

    public function test_an_over_limit_pptx_shows_a_too_large_message_not_a_generic_failure(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        // 決定的に上限超過を再現するため、上限を小さく設定する
        // (実ファイルを20MB超えで作るのは低速なため)。
        config(['analysis_attachment.max_file_size_bytes' => 1024]);
        $source = $this->makeSourceAnalysis();

        $response = $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
            'sales_deck' => $this->pptxUpload('営業資料.pptx', ['内容1', '参照元']),
        ]);

        $response->assertSessionHasErrors('sales_deck');
        $this->assertStringContainsString('サイズ', session('errors')->get('sales_deck')[0]);
        $this->assertSame(0, Analysis::query()->whereNotNull('source_analysis_id')->count());
    }

    public function test_comparison_created_with_an_attachment_shows_the_insertion_link_on_its_detail_page(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
            'sales_deck' => $this->pptxUpload('営業資料.pptx', ['内容1', '参照元']),
        ])->assertRedirect();

        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();
        $this->makeCompletedPdfReport($comparison);

        $response = $this->asAdmin()->get("/admin/analyses/{$comparison->id}");
        $response->assertOk();
        $response->assertSee('営業資料に差し込む');
    }

    public function test_comparison_created_without_an_attachment_shows_the_guidance_text(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $source = $this->makeSourceAnalysis();

        $this->asAdmin()->post("/admin/analyses/{$source->id}/compare", [
            'self_url' => 'https://self.example.com',
            'competitor_urls' => $this->validCompetitorUrls(3),
            'competitor_names' => $this->validCompetitorNames(3),
        ])->assertRedirect();

        $comparison = Analysis::query()->whereNotNull('source_analysis_id')->firstOrFail();
        $this->makeCompletedPdfReport($comparison);

        $response = $this->asAdmin()->get("/admin/analyses/{$comparison->id}");
        $response->assertOk();
        $response->assertSee('営業資料(PPTX)をアップロードすると');
    }
}
