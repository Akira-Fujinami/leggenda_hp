<?php

namespace Tests\Feature\Report;

use App\Enums\AnalysisStatus;
use App\Enums\MetricResultStatus;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\BrandWheelImprovementSuggestion;
use App\Models\LeadSession;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Report\ReportViewModelBuilder;
use Database\Seeders\CategoryDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ReportViewModelBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategoryDefinitionSeeder::class);
    }

    private function makeWebsiteAnalysis(Analysis $analysis, bool $isPrimary): WebsiteAnalysis
    {
        $website = Website::factory()->create(['project_id' => $analysis->project_id, 'is_primary' => $isPrimary]);

        return WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
    }

    private function recordSuccessfulMetric(WebsiteAnalysis $wa, string $categoryKey, float $score, float $maxScore): void
    {
        $definition = MetricDefinition::factory()->create([
            'category_key' => $categoryKey,
            'source_type' => 'static_html',
            'weight' => $maxScore,
        ]);

        MetricResult::factory()->create([
            'website_analysis_id' => $wa->id,
            'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success,
            'score' => $score,
            'max_score' => $maxScore,
        ]);
    }

    /**
     * 2026-08-08: 内部の7カテゴリ/4観点由来のフィールド(selfScore/
     * competitorScore/overallSummaryText/comparisonSentence/perspectives/
     * topRecommendations)はレポートから削除された(該当ページ自体を
     * 削除したため)。ここでは、それらが無くてもViewModelが正常に組み立つ
     * ことと、companyDisplayName/isPartialが引き続き正しく設定されることを
     * 確認する。
     */
    public function test_a_self_only_analysis_produces_a_view_model_without_competitor_data(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $this->recordSuccessfulMetric($selfWa, 'accessibility', 8, 10);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNull($viewModel->competitorWebsiteUrl);
        $this->assertNull($viewModel->brandWheelCompetitor);
        $this->assertSame('株式会社サンプル様', $viewModel->companyDisplayName);
        $this->assertFalse($viewModel->isPartial);
    }

    public function test_a_partial_analysis_still_produces_a_view_model(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Partial]);
        $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertTrue($viewModel->isPartial);
        $this->assertIsArray($viewModel->brandWheelSelf);
    }

    /**
     * 依頼Q-1: ReportViewModel::$crawlSiteEnabledはAnalysis.crawl_siteを
     * そのまま複製する。
     */
    public function test_crawl_site_enabled_reflects_the_analysis_crawl_site_flag(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $user = User::factory()->create()->id;

        // Website.is_primaryはproject単位で高々1件のため、2つのAnalysisを
        // 別々のProjectに分ける(1つのProjectを使い回すと一意制約違反になる)。
        $crawlingProject = new Project(['name' => 'テスト(巡回あり)']);
        $crawlingProject->user_id = $user;
        $crawlingProject->lead_session_id = $leadSession->id;
        $crawlingProject->save();
        $crawlingAnalysis = Analysis::factory()->create(['project_id' => $crawlingProject->id, 'status' => AnalysisStatus::Completed, 'crawl_site' => true]);
        $this->makeWebsiteAnalysis($crawlingAnalysis, isPrimary: true);
        $this->assertTrue(app(ReportViewModelBuilder::class)->build($crawlingAnalysis, $leadSession)->crawlSiteEnabled);

        $nonCrawlingProject = new Project(['name' => 'テスト(巡回なし)']);
        $nonCrawlingProject->user_id = $user;
        $nonCrawlingProject->lead_session_id = $leadSession->id;
        $nonCrawlingProject->save();
        $nonCrawlingAnalysis = Analysis::factory()->create(['project_id' => $nonCrawlingProject->id, 'status' => AnalysisStatus::Completed, 'crawl_site' => false]);
        $this->makeWebsiteAnalysis($nonCrawlingAnalysis, isPrimary: true);
        $this->assertFalse(app(ReportViewModelBuilder::class)->build($nonCrawlingAnalysis, $leadSession)->crawlSiteEnabled);
    }

    public function test_brand_wheel_is_composed_for_self_and_competitor_websites(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, isPrimary: false);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => '自社サイトの抜粋']]]],
            'key_message' => '自社のキーメッセージ',
            // 2026-08-08: impressionはstringからlist<string>へ変更。
            'impression' => ['自社の印象その1', '自社の印象その2'],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'asset', 'matched_sub_elements' => [['key' => 'brand_recognition', 'evidence' => '競合サイトの抜粋']]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame('success', $viewModel->brandWheelSelf['status']);
        $this->assertSame('自社のキーメッセージ', $viewModel->brandWheelSelf['key_message']);
        // 'impression'は画面(frontend)向けに従来通り読点連結の文字列のまま、
        // 'impression_items'はレポート向けに配列そのものを新たに公開する。
        $this->assertSame('自社の印象その1、自社の印象その2', $viewModel->brandWheelSelf['impression']);
        $this->assertSame(['自社の印象その1', '自社の印象その2'], $viewModel->brandWheelSelf['impression_items']);
        $this->assertSame('success', $viewModel->brandWheelCompetitor['status']);
        $this->assertNotEmpty($viewModel->brandWheelComparison['self_points']);
        // 2026-08-08: 「競合サイトの分析結果」ページ新設に伴いcompetitor_points
        // を復活。
        $this->assertNotEmpty($viewModel->brandWheelComparison['competitor_points']);

        // evidence(原文の抜粋)は$brandWheelSelf/$brandWheelCompetitor
        // (BrandWheelLeadResponseComposerの戻り値、JSON APIと共有)には
        // 一切渡さない。
        $raw = json_encode($viewModel->brandWheelSelf, JSON_UNESCAPED_UNICODE).json_encode($viewModel->brandWheelCompetitor, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('自社サイトの抜粋', $raw);
        $this->assertStringNotContainsString('競合サイトの抜粋', $raw);
    }

    /**
     * status!=='success'のとき(6項目すべて0件の表を出すことは禁止のため)
     * axesが空配列でReportViewModelへ渡ること。実際に表を出さない判断は
     * Blade/WordReportGenerator側のstatus分岐に一任する。
     */
    public function test_brand_wheel_axes_are_empty_when_status_is_not_success(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'insufficient_input',
            'source_pages' => ['recruit_page' => 'absent', 'home_page' => 'read'],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame('insufficient_input', $viewModel->brandWheelSelf['status']);
        $this->assertSame([], $viewModel->brandWheelSelf['axes']);
        $this->assertNotNull($viewModel->brandWheelSelf['status_message']);
        $this->assertNull($viewModel->brandWheelComparison['one_point']);
    }

    public function test_brand_wheel_competitor_is_null_for_a_self_only_analysis(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNull($viewModel->brandWheelCompetitor);
        // 競合が存在しない場合もcompetitor_pointsキー自体は常に存在する
        // (競合axesが空配列のためpoints()が空リストを返すだけ)。
        $this->assertArrayHasKey('competitor_points', $viewModel->brandWheelComparison);
        $this->assertSame([], $viewModel->brandWheelComparison['competitor_points']);
    }

    /**
     * 2026-08-08: レーダー画像は自社単独(3ページ目)・競合単独(4ページ目)・
     * 自社×競合の重ね図(対比表ページ)の3種類に分割された(旧
     * brandWheelRadarPngは廃止)。ここではビルダーがnull以外を返すこと
     * (=実際に呼び出して生成できていること)だけを確認する。ラスタライズ
     * 経路(rsvg-convert)自体はBrandWheelHexagonRendererTestで別途検証済み。
     */
    public function test_brand_wheel_radar_png_is_generated_when_self_axes_are_readable(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNotNull($viewModel->brandWheelRadarPngSelf);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($viewModel->brandWheelRadarPngSelf, 0, 8));
        // 競合が存在しないため、競合単独図は生成されない。
        $this->assertNull($viewModel->brandWheelRadarPngCompetitor);
        // 重ね図(brandWheelRadarPngComparison)自体は自社が読み取り可能な
        // 限り生成される(競合系列が無いだけの自社単独図になる) ――
        // 競合の有無による表示・非表示の判断はBlade側
        // ($competitorReadable && $viewModel->brandWheelRadarPngComparison)
        // に一任する(このビルダーの責務ではない)。
        $this->assertNotNull($viewModel->brandWheelRadarPngComparison);
    }

    /**
     * 6項目すべて0件の図(「魅力のない会社」の意味になる)を出さないのと
     * 同じ理由で、status!=='success'のときはPNGそのものを生成しない
     * (呼び出し側で画像を省略し、表だけで成立させるための前提)。
     */
    public function test_brand_wheel_radar_png_is_null_when_self_status_is_not_success(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'insufficient_input',
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNull($viewModel->brandWheelRadarPngSelf);
    }

    /**
     * 自社・競合ともにstatus==='success'のとき、競合単独図(4ページ目)・
     * 自社×競合の重ね図(対比表ページ)の両方が生成される。競合単独図は
     * 常に自社色(青)ではなく競合色(オレンジ)で描かれる必要がある
     * (2026-08-08、BrandWheelRadarSvgBuilder::competitorColor()を明示的に
     * 渡すバグ修正の回帰確認)。
     */
    public function test_brand_wheel_radar_pngs_are_generated_for_competitor_and_comparison_when_both_sides_are_readable(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, isPrimary: false);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'asset', 'matched_sub_elements' => [['key' => 'brand_recognition', 'evidence' => 'y']]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNotNull($viewModel->brandWheelRadarPngSelf);
        $this->assertNotNull($viewModel->brandWheelRadarPngCompetitor);
        $this->assertNotNull($viewModel->brandWheelRadarPngComparison);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($viewModel->brandWheelRadarPngCompetitor, 0, 8));
    }

    /**
     * 2026-08-04: 「自社ページの分析結果」「24項目の対比」「改善提案」の
     * 3ページが同じ合計件数を参照するよう、selfTotalMatched等は
     * brandWheelSelf['axes']のmatched_count合計と必ず一致する
     * (docs/lead-report-layout/README.md「合計件数Nは3・6・8ページ目で
     * 必ず同じソースから算出すること」)。
     */
    public function test_self_and_competitor_totals_match_the_sum_of_the_axis_cards_matched_counts(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, isPrimary: false);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => 'x'], ['key' => 'business_expansion', 'evidence' => 'y'],
                ]],
            ],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'asset', 'matched_sub_elements' => [['key' => 'brand_recognition', 'evidence' => 'z']]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $expectedSelfMatched = array_sum(array_column($viewModel->brandWheelSelf['axes'], 'matched_count'));
        $expectedSelfMax = array_sum(array_column($viewModel->brandWheelSelf['axes'], 'max_count'));
        $expectedCompetitorMatched = array_sum(array_column($viewModel->brandWheelCompetitor['axes'], 'matched_count'));

        $this->assertSame($expectedSelfMatched, $viewModel->selfTotalMatched);
        $this->assertSame(2, $viewModel->selfTotalMatched);
        $this->assertSame($expectedSelfMax, $viewModel->selfTotalMax);
        $this->assertSame($expectedCompetitorMatched, $viewModel->competitorTotalMatched);
        $this->assertSame(1, $viewModel->competitorTotalMatched);
    }

    /**
     * 2026-08-08: △(見出し・リンクラベルのみ、discarded_sub_elementsの
     * reason==='label_only_evidence')の参考件数も、matched_countと同じく
     * axesから1回だけ集計される。対比表の「(参考)」表示専用で、合計
     * (selfTotalMatched等)には含めない。
     */
    public function test_self_and_competitor_total_label_only_counts_are_aggregated_from_the_axes(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, isPrimary: false);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => 'x'],
                ], 'discarded_sub_elements' => [
                    ['key' => 'business_expansion', 'evidence' => '事業紹介', 'reason' => 'label_only_evidence'],
                    ['key' => 'project_initiative', 'evidence' => '存在しない抜粋', 'reason' => 'evidence_not_found'],
                ]],
            ],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'asset', 'matched_sub_elements' => [
                    ['key' => 'brand_recognition', 'evidence' => 'z'],
                ], 'discarded_sub_elements' => [
                    ['key' => 'office_facility', 'evidence' => '福利厚生', 'reason' => 'label_only_evidence'],
                    ['key' => 'competitiveness', 'evidence' => '福利厚生2', 'reason' => 'label_only_evidence'],
                ]],
            ],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        // evidence_not_foundは△に含まれない(label_only_evidenceのみを数える)。
        $this->assertSame(1, $viewModel->selfTotalLabelOnly);
        $this->assertSame(2, $viewModel->competitorTotalLabelOnly);
        // △は合計(selfTotalMatched)には含まれない。
        $this->assertSame(1, $viewModel->selfTotalMatched);
    }

    public function test_sub_element_comparison_lists_all_24_items_with_self_and_competitor_matched_flags(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertCount(24, $viewModel->subElementComparison);
        $purpose = collect($viewModel->subElementComparison)->firstWhere('sub_key', 'purpose');
        $this->assertTrue($purpose['self_matched']);
        $this->assertFalse($purpose['competitor_matched']);
    }

    /**
     * 競合が存在しない(自社単独レポート)場合、improvementFocusはnull
     * (「改善提案」ページのグループ差・3項目は競合との比較が前提のため)。
     */
    public function test_improvement_focus_is_null_when_there_is_no_competitor(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNull($viewModel->improvementFocus);
    }

    /**
     * 2026-08-10: 競合が存在しない場合、improvementFocusはnullのままだが、
     * 代わりにimprovementFocusSelfOnly(自社の「－」「△」項目のみで構成)が
     * 組み立てられる ―― 「比較サイトが無いため、領域ごとの比較はご用意
     * できません。」の1行だけでページの大半が空白になる問題への対応
     * (ユーザー指摘)。
     */
    public function test_improvement_focus_self_only_is_populated_when_there_is_no_competitor(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        // will_activity軸のpurposeだけ該当あり、残り23項目は「－」。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNull($viewModel->improvementFocus);
        $this->assertNotNull($viewModel->improvementFocusSelfOnly);
        $this->assertCount(3, $viewModel->improvementFocusSelfOnly['items']);
        foreach ($viewModel->improvementFocusSelfOnly['items'] as $item) {
            $this->assertArrayNotHasKey('competitor_evidence', $item);
            $this->assertContains($item['self_reason'], ['none', 'label_only']);
        }
    }

    /**
     * 依頼Q-2最重要(2026-08-25、レポート35の再現): 自社が閾値以上
     * (=AIが呼ばれる)かつ競合が無い/不十分なとき、3枚のカードは改善提案AI
     * (BrandWheelImprovementSuggestion.focus_sub_element_keys)由来に差し替わり、
     * items_source='ai'になること。規則(composeSelfOnly())が選ぶ領域
     * (personality/company_distanceグループ、全4件が「－」で最多)と、AIが
     * 選ぶ領域(company_appealグループのasset軸)が異なっていても、カードは
     * AI側の選択で統一されること(1ページ1推奨の確認)。
     */
    public function test_improvement_focus_self_only_items_come_from_ai_focus_keys_when_self_is_sufficient(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        // 自社matched=6(will_activity4件+asset2件、閾値ちょうど)。
        // personality軸(会社との距離グループ)は0/4で「－」が最多 ――
        // 規則(composeSelfOnly())はcompany_distanceグループを選ぶはず。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => 'a'], ['key' => 'business_expansion', 'evidence' => 'b'],
                    ['key' => 'project_initiative', 'evidence' => 'c'], ['key' => 'social_contribution', 'evidence' => 'd'],
                ]],
                ['axis_key' => 'asset', 'matched_sub_elements' => [
                    ['key' => 'brand_recognition', 'evidence' => 'e'], ['key' => 'competitiveness', 'evidence' => 'f'],
                ]],
            ],
        ]);

        // AIはcompany_appealグループ(asset軸)のscale_influence/office_facilityを
        // 選ぶ(規則の選択=company_distanceとは異なる領域)。
        BrandWheelImprovementSuggestion::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => 'success',
            'one_point' => 'まずは規模・影響力とオフィス・施設を具体的に紹介することを推奨します。',
            'reason' => '候補者は働く環境の規模感を知りたいと考えられます。',
            'focus_sub_element_keys' => ['scale_influence', 'office_facility'],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame(6, $viewModel->selfTotalMatched);
        $this->assertNotNull($viewModel->improvementFocusSelfOnly);
        $this->assertSame('ai', $viewModel->improvementFocusSelfOnly['items_source']);
        $this->assertCount(2, $viewModel->improvementFocusSelfOnly['items']);
        $subNames = array_column($viewModel->improvementFocusSelfOnly['items'], 'sub_name');
        $this->assertSame(['規模・影響力', 'オフィス・施設'], $subNames);
        // 規則が選ぶはずだったcompany_distanceグループの項目(リーダーシップ等)は
        // カードに出ない ―― 1ページに2つの領域が混ざらないことの確認。
        $this->assertNotContains('リーダーシップ', $subNames);
        // groups(棒グラフ)は規則由来のまま(無改修)。
        $this->assertNotEmpty($viewModel->improvementFocusSelfOnly['groups']);
    }

    /**
     * 依頼Q-2: AIが挙げたキーに、既に自社で○(matched)の項目のキーが
     * 混ざっていた場合、防御的フィルタでそのキーだけを除外すること
     * (プロンプト側の指示に従わなかった場合の最後の防波堤)。
     */
    public function test_improvement_focus_self_only_ai_items_exclude_already_matched_keys(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => 'a'], ['key' => 'business_expansion', 'evidence' => 'b'],
                    ['key' => 'project_initiative', 'evidence' => 'c'], ['key' => 'social_contribution', 'evidence' => 'd'],
                ]],
                ['axis_key' => 'asset', 'matched_sub_elements' => [
                    ['key' => 'brand_recognition', 'evidence' => 'e'], ['key' => 'competitiveness', 'evidence' => 'f'],
                ]],
            ],
        ]);

        // 'purpose'は既に○(matched)。指示違反を模す。
        BrandWheelImprovementSuggestion::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => 'success',
            'one_point' => 'まずはオフィス・施設を具体的に紹介することを推奨します。',
            'focus_sub_element_keys' => ['purpose', 'office_facility'],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame('ai', $viewModel->improvementFocusSelfOnly['items_source']);
        $subNames = array_column($viewModel->improvementFocusSelfOnly['items'], 'sub_name');
        $this->assertSame(['オフィス・施設'], $subNames);
    }

    /**
     * 依頼Q-2: 改善提案AIが未生成/失敗、またはfocus_sub_element_keysが
     * 有効な項目を1件も含まない場合は、従来どおり規則
     * (composeSelfOnly())由来のitemsにフォールバックすること
     * (「誤ったカードを出すくらいなら規則由来のほうがマシ」という
     * 依頼者方針)。
     */
    public function test_improvement_focus_self_only_falls_back_to_rule_items_when_ai_suggestion_is_unavailable(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => 'a'], ['key' => 'business_expansion', 'evidence' => 'b'],
                    ['key' => 'project_initiative', 'evidence' => 'c'], ['key' => 'social_contribution', 'evidence' => 'd'],
                ]],
                ['axis_key' => 'asset', 'matched_sub_elements' => [
                    ['key' => 'brand_recognition', 'evidence' => 'e'], ['key' => 'competitiveness', 'evidence' => 'f'],
                ]],
            ],
        ]);
        // 改善提案AIの行自体を作らない(未生成/生成中を模す)。

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame(6, $viewModel->selfTotalMatched);
        $this->assertNotNull($viewModel->improvementFocusSelfOnly);
        $this->assertSame('rule', $viewModel->improvementFocusSelfOnly['items_source']);
        $this->assertNotEmpty($viewModel->improvementFocusSelfOnly['items']);
    }

    /**
     * 自社・競合ともにstatus==='success'のとき、improvementFocusが
     * 実際に組み立てられ、選ばれた項目に競合サイトのevidenceが付くこと。
     */
    public function test_improvement_focus_is_populated_with_competitor_evidence_when_both_sides_are_readable(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, isPrimary: false);

        // 自社はrelationship軸が0件、競合は3件該当 ―― company_distanceグループの
        // 差が最大になるように仕組む。競合の合計matched件数は6以上
        // (comparison_sufficiency_threshold)にする ―― asset/financial_benefitの
        // 追加分はcompany_distanceのgapを超えないよう振り分けており
        // (asset: gap=2-1=1、financial_benefit: gap=2-0=2で同点だが
        // company_distanceが先に評価され`>`比較のため据え置かれる)、
        // 選定される領域・3項目のcompetitor_evidenceには影響しない。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => '自社の抜粋']]]],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'relationship', 'matched_sub_elements' => [
                    ['key' => 'colleagues', 'evidence' => '同僚についての競合サイトの抜粋'],
                    ['key' => 'atmosphere', 'evidence' => '雰囲気についての競合サイトの抜粋'],
                ]],
                ['axis_key' => 'asset', 'matched_sub_elements' => [
                    ['key' => 'brand_recognition', 'evidence' => 'y1'], ['key' => 'competitiveness', 'evidence' => 'y2'],
                ]],
                ['axis_key' => 'financial_benefit', 'matched_sub_elements' => [
                    ['key' => 'salary_level', 'evidence' => 'y3'], ['key' => 'benefits', 'evidence' => 'y4'],
                ]],
            ],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNotNull($viewModel->improvementFocus);
        $this->assertSame('company_distance', $viewModel->improvementFocus['selected_group']);
        // 依頼AH-1(2026-08-28): ①(colleagues/atmosphere)は2件のみのため、
        // ②(競合にも自社にも無い項目)が1件補われ、合計3件になる。
        $this->assertCount(3, $viewModel->improvementFocus['items']);
        $evidences = array_column($viewModel->improvementFocus['items'], 'competitor_evidence');
        $this->assertContains('同僚についての競合サイトの抜粋', $evidences);
        $this->assertContains('雰囲気についての競合サイトの抜粋', $evidences);
    }

    /**
     * @return array{Analysis, LeadSession}
     */
    private function makeImprovementFocusFixtureWithColleaguesAndAtmosphere(): array
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, isPrimary: false);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            // 依頼AF-3のmid_term_action関連テスト向けに、自社の合計matched
            // 件数をcomparison_sufficiency_threshold(既定6)以上にする
            // (will_activity全4件+asset2件=6件)。relationship軸には触れず
            // company_distanceグループの差(colleagues/atmosphereの選定)には
            // 影響しない。
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => '自社の抜粋1'], ['key' => 'business_expansion', 'evidence' => '自社の抜粋2'],
                    ['key' => 'project_initiative', 'evidence' => '自社の抜粋3'], ['key' => 'social_contribution', 'evidence' => '自社の抜粋4'],
                ]],
                ['axis_key' => 'asset', 'matched_sub_elements' => [
                    ['key' => 'scale_influence', 'evidence' => '自社の抜粋5'], ['key' => 'office_facility', 'evidence' => '自社の抜粋6'],
                ]],
            ],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'relationship', 'matched_sub_elements' => [
                    ['key' => 'colleagues', 'evidence' => '同僚についての競合サイトの抜粋'],
                    ['key' => 'atmosphere', 'evidence' => '雰囲気についての競合サイトの抜粋'],
                ]],
                ['axis_key' => 'asset', 'matched_sub_elements' => [
                    ['key' => 'brand_recognition', 'evidence' => 'y1'], ['key' => 'competitiveness', 'evidence' => 'y2'],
                ]],
                ['axis_key' => 'financial_benefit', 'matched_sub_elements' => [
                    ['key' => 'salary_level', 'evidence' => 'y3'], ['key' => 'benefits', 'evidence' => 'y4'],
                ]],
            ],
        ]);

        return [$analysis, $leadSession];
    }

    // ------------------------------------------------------------------
    // 依頼AF-2(2026-08-27): 依頼W-2で消えたまま埋め戻していなかった「理由」の
    // 復活。実際に表示されているカードの項目(improvementFocus['items'])に
    // 対応した理由のみを表示する。
    // ------------------------------------------------------------------

    public function test_improvement_reason_is_shown_when_it_matches_the_currently_displayed_focus_items(): void
    {
        [$analysis, $leadSession] = $this->makeImprovementFocusFixtureWithColleaguesAndAtmosphere();

        // 依頼AH-1(2026-08-28): このフィクスチャは①(colleagues/atmosphere)が
        // 2件のみのため、②(競合にも自社にも無い項目)が1件補われ、実際に
        // 表示されるカードは3件(colleagues/atmosphere/leadership)になる。
        // 生成時点の選定(focus_items_reason_sub_names)もAH-1の新しい選定結果に
        // 揃えないと一致チェックが失敗する(依頼AH-3、この一致チェック自体は
        // 依頼AF-2の安全網のまま無改修)。
        BrandWheelImprovementSuggestion::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => 'success',
            'focus_items_reason' => '同僚・先輩像と職場の雰囲気は、候補者が働くイメージを持つうえで重要な情報です。',
            'focus_items_reason_sub_names' => ['同僚・先輩像', '職場の雰囲気', 'リーダーシップ'],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNotNull($viewModel->improvementFocus);
        $this->assertSame(['同僚・先輩像', '職場の雰囲気', 'リーダーシップ'], array_column($viewModel->improvementFocus['items'], 'sub_name'));
        $this->assertSame('catch_up', $viewModel->improvementFocus['items'][0]['type']);
        $this->assertSame('catch_up', $viewModel->improvementFocus['items'][1]['type']);
        $this->assertSame('breakout', $viewModel->improvementFocus['items'][2]['type']);
        $this->assertSame('同僚・先輩像と職場の雰囲気は、候補者が働くイメージを持つうえで重要な情報です。', $viewModel->improvementReason);
    }

    /**
     * 生成時点の選定(focus_items_reason_sub_names)と、いま実際に表示する
     * カードの項目が一致しない場合は、理由のブロックを出さない
     * (依頼AA-3と同じ「失敗してもレポート生成自体は失敗させない」方針。
     * カードの項目と理由の対象が食い違ったまま表示することを防ぐ)。
     */
    public function test_improvement_reason_is_hidden_when_stored_sub_names_do_not_match_the_current_focus_items(): void
    {
        [$analysis, $leadSession] = $this->makeImprovementFocusFixtureWithColleaguesAndAtmosphere();

        BrandWheelImprovementSuggestion::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => 'success',
            'focus_items_reason' => '実際には別の項目について書かれた理由です。',
            'focus_items_reason_sub_names' => ['組織構造'],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNotNull($viewModel->improvementFocus);
        $this->assertNull($viewModel->improvementReason);
    }

    /**
     * 理由の生成に失敗した(focus_items_reasonがnullのまま保存された)場合、
     * 理由のブロックだけが出ず、レポート自体は正常に完成すること。
     */
    public function test_improvement_reason_is_hidden_without_failing_the_report_when_generation_failed(): void
    {
        [$analysis, $leadSession] = $this->makeImprovementFocusFixtureWithColleaguesAndAtmosphere();

        BrandWheelImprovementSuggestion::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => 'success',
            'focus_items_reason' => null,
            'focus_items_reason_sub_names' => ['同僚・先輩像', '職場の雰囲気'],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNotNull($viewModel->improvementFocus);
        $this->assertNull($viewModel->improvementReason);
        $this->assertNotNull($viewModel->improvementOnePoint);
    }

    /**
     * 生成時点でBrandWheelImprovementSuggestion行自体が無い(まだ生成中)場合も、
     * 理由は出さないだけでレポートは完成する。
     */
    public function test_improvement_reason_is_null_when_no_suggestion_row_exists_yet(): void
    {
        [$analysis, $leadSession] = $this->makeImprovementFocusFixtureWithColleaguesAndAtmosphere();

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNotNull($viewModel->improvementFocus);
        $this->assertNull($viewModel->improvementReason);
    }

    // ------------------------------------------------------------------
    // 依頼AI-3(2026-08-28): 依頼AF-2の一致チェックが失敗しても何のログも
    // 出ず、理由のブロックが静かに消えるだけだった(依頼AHでcompose()の
    // 選定ロジックを変えた際に実際に起きた「沈黙する失敗」)。一致チェック
    // 自体(依頼W-2の安全網)は外さず、ログを1件出すだけにする。
    // ------------------------------------------------------------------

    /**
     * 生成時点の選定(focus_items_reason_sub_names)と、表示時のカードの
     * 項目が一致しない場合、構造化ログが1件出ること。本文・APIキー・
     * 顧客情報(evidence等)は含めず、analysis_id・保存されていた項目名・
     * 表示時の項目名・不一致であることが分かる情報のみを含める。
     */
    public function test_logs_a_warning_when_stored_focus_sub_names_do_not_match_the_current_focus_items(): void
    {
        [$analysis, $leadSession] = $this->makeImprovementFocusFixtureWithColleaguesAndAtmosphere();

        BrandWheelImprovementSuggestion::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => 'success',
            'focus_items_reason' => '実際には別の項目について書かれた理由です。',
            'focus_items_reason_sub_names' => ['組織構造'],
        ]);

        Log::spy();

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNull($viewModel->improvementReason);
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($analysis) {
                $encoded = json_encode($context, JSON_UNESCAPED_UNICODE);

                return str_contains($message, 'no longer match')
                    && $context['analysis_id'] === $analysis->id
                    && $context['stored_focus_sub_names'] === ['組織構造']
                    && $context['current_focus_sub_names'] === ['同僚・先輩像', '職場の雰囲気', 'リーダーシップ']
                    // 本文・APIキー・顧客情報を含まないこと(理由本文・evidence等)。
                    && ! str_contains($encoded, '実際には別の項目について書かれた理由です')
                    && ! str_contains($encoded, 'test-key')
                    && ! str_contains(mb_strtolower($encoded), 'api_key');
            })
            ->once();
    }

    /**
     * 一致している場合はこのログが出ないこと(過渡状態や正常系でノイズに
     * ならないようにする)。
     */
    public function test_does_not_log_a_warning_when_stored_focus_sub_names_match_the_current_focus_items(): void
    {
        [$analysis, $leadSession] = $this->makeImprovementFocusFixtureWithColleaguesAndAtmosphere();

        BrandWheelImprovementSuggestion::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => 'success',
            'focus_items_reason' => '同僚・先輩像と職場の雰囲気は、候補者が働くイメージを持つうえで重要な情報です。',
            'focus_items_reason_sub_names' => ['同僚・先輩像', '職場の雰囲気', 'リーダーシップ'],
        ]);

        Log::spy();

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNotNull($viewModel->improvementReason);
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * 提案行自体がまだ無い(生成中)場合は、不一致の警告ログを出さない ――
     * 生成完了後の不一致だけを異常として扱う(生成中は頻繁に起こる正常な
     * 過渡状態のため、ノイズにしない)。
     */
    public function test_does_not_log_a_warning_when_no_suggestion_row_exists_yet(): void
    {
        [$analysis, $leadSession] = $this->makeImprovementFocusFixtureWithColleaguesAndAtmosphere();

        Log::spy();

        app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        Log::shouldNotHaveReceived('warning');
    }

    // ------------------------------------------------------------------
    // 依頼AF-3(2026-08-27、依頼者承認済み): 「理由」「中長期の差別化
    // ポイント」が両方とも無いときの代替文言。
    // ------------------------------------------------------------------

    public function test_improvement_fallback_note_is_shown_when_reason_and_mid_term_action_are_both_unavailable(): void
    {
        [$analysis, $leadSession] = $this->makeImprovementFocusFixtureWithColleaguesAndAtmosphere();

        // 理由(AI生成失敗でnull)・中長期(mutually_unmatched_itemsが空でnull)
        // の両方が欠けた状態を作る。
        BrandWheelImprovementSuggestion::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => 'success',
            'focus_items_reason' => null,
            'focus_items_reason_sub_names' => [],
            'mid_term_action' => null,
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNotEmpty($viewModel->improvementFocus['items']);
        $this->assertNull($viewModel->improvementReason);
        $this->assertNull($viewModel->improvementMidTermAction);
        $this->assertSame(
            (string) config('brand_wheel.improvement_focus_templates.no_reason_and_mid_term_fallback'),
            $viewModel->improvementFallbackNote,
        );
    }

    public function test_improvement_fallback_note_is_null_when_mid_term_action_is_present(): void
    {
        [$analysis, $leadSession] = $this->makeImprovementFocusFixtureWithColleaguesAndAtmosphere();

        BrandWheelImprovementSuggestion::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => 'success',
            'focus_items_reason' => null,
            'focus_items_reason_sub_names' => [],
            'mid_term_action' => '中長期的には、社員インタビューの連載化も検討できます。',
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNull($viewModel->improvementReason);
        $this->assertNotNull($viewModel->improvementMidTermAction);
        $this->assertNull($viewModel->improvementFallbackNote);
    }

    public function test_improvement_fallback_note_is_null_when_reason_is_present(): void
    {
        [$analysis, $leadSession] = $this->makeImprovementFocusFixtureWithColleaguesAndAtmosphere();

        // 依頼AH-1: このフィクスチャは②が1件補われ3件になるため、一致
        // チェックのため生成時点の選定にも「リーダーシップ」を含める
        // (上のtest_improvement_reason_is_shown_...と同じ理由)。
        BrandWheelImprovementSuggestion::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => 'success',
            'focus_items_reason' => '同僚・先輩像と職場の雰囲気は、候補者が働くイメージを持つうえで重要な情報です。',
            'focus_items_reason_sub_names' => ['同僚・先輩像', '職場の雰囲気', 'リーダーシップ'],
            'mid_term_action' => null,
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNotNull($viewModel->improvementReason);
        $this->assertNull($viewModel->improvementMidTermAction);
        $this->assertNull($viewModel->improvementFallbackNote);
    }

    // ------------------------------------------------------------------
    // 2026-08-17改修分: ポジティブ/ネガティブ印象・比較サマリー・改善提案AI。
    // ------------------------------------------------------------------

    public function test_positive_and_negative_impression_are_exposed_on_the_view_model(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
            'positive_impression' => '事業内容への取り組み姿勢が伝わり、良い印象を与える可能性があります。',
            'negative_impression' => '働く環境の具体像がイメージしづらい可能性があります。',
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame('事業内容への取り組み姿勢が伝わり、良い印象を与える可能性があります。', $viewModel->brandWheelSelf['positive_impression']);
        $this->assertSame('働く環境の具体像がイメージしづらい可能性があります。', $viewModel->brandWheelSelf['negative_impression']);
    }

    public function test_group_totals_and_comparison_overview_are_populated_when_both_sides_are_readable(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, isPrimary: false);

        // 自社・競合とも合計matched件数を6以上(comparison_sufficiency_threshold)
        // にする ―― 閾値未満だと修正3によりgroupTotals/comparisonOverviewが
        // 空配列になるため、この「両方十分」ケースの回帰確認には閾値以上の
        // フィクスチャが必要。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => 'x1'], ['key' => 'business_expansion', 'evidence' => 'x2'],
                    ['key' => 'project_initiative', 'evidence' => 'x3'], ['key' => 'social_contribution', 'evidence' => 'x4'],
                ]],
                ['axis_key' => 'asset', 'matched_sub_elements' => [
                    ['key' => 'brand_recognition', 'evidence' => 'x5'], ['key' => 'competitiveness', 'evidence' => 'x6'],
                ]],
            ],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'relationship', 'matched_sub_elements' => [
                    ['key' => 'colleagues', 'evidence' => 'y'], ['key' => 'atmosphere', 'evidence' => 'z'],
                ]],
                ['axis_key' => 'asset', 'matched_sub_elements' => [
                    ['key' => 'brand_recognition', 'evidence' => 'y1'], ['key' => 'competitiveness', 'evidence' => 'y2'],
                    ['key' => 'scale_influence', 'evidence' => 'y3'], ['key' => 'office_facility', 'evidence' => 'y4'],
                ]],
            ],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertCount(3, $viewModel->groupTotals);
        $companyDistance = collect($viewModel->groupTotals)->firstWhere('group', 'company_distance');
        $this->assertSame('competitor_advantage', $companyDistance['verdict']);
        $this->assertNotEmpty($viewModel->comparisonOverview);
    }

    public function test_group_totals_and_comparison_overview_are_empty_when_there_is_no_competitor(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame([], $viewModel->groupTotals);
        $this->assertSame([], $viewModel->comparisonOverview);
    }

    /**
     * 改善提案AI(BrandWheelImprovementSuggestion)がまだ生成されていない/
     * 失敗している場合、improvementOnePointは既存の決定的ロジック
     * ($brandWheelComparison['one_point']['text'])へ自動フォールバックする
     * (AI障害・遅延がレポート生成全体を止めない設計、2026-08-17)。
     */
    public function test_improvement_one_point_falls_back_to_the_deterministic_text_when_no_ai_suggestion_exists(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame($viewModel->brandWheelComparison['one_point']['text'], $viewModel->improvementOnePoint);
        $this->assertNull($viewModel->improvementRecommendation);
        $this->assertNull($viewModel->improvementReason);
        $this->assertSame([], $viewModel->improvementRecommendedContents);
        $this->assertNull($viewModel->improvementMidTermAction);
    }

    public function test_improvement_one_point_and_recommendation_use_the_ai_suggestion_when_available(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        // 自社の合計matched件数を6以上(comparison_sufficiency_threshold)にする
        // ―― 閾値未満だと修正2によりAI結果は使われず決定的フォールバックに
        // なるため、この「AI結果が使われる」ケースの回帰確認には閾値以上の
        // フィクスチャが必要。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => 'x1'], ['key' => 'business_expansion', 'evidence' => 'x2'],
                    ['key' => 'project_initiative', 'evidence' => 'x3'], ['key' => 'social_contribution', 'evidence' => 'x4'],
                ]],
                ['axis_key' => 'asset', 'matched_sub_elements' => [
                    ['key' => 'brand_recognition', 'evidence' => 'x5'], ['key' => 'competitiveness', 'evidence' => 'x6'],
                ]],
            ],
        ]);
        BrandWheelImprovementSuggestion::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => 'success',
            'one_point' => 'まずは既存情報だけで追加できる仕事内容・キャリア情報から充実させましょう。',
            'recommendation' => 'まずは仕事の魅力に関する情報を拡充することを推奨します。',
            'reason' => '仕事の魅力は競合が複数件読み取れているのに対し自社は0件で、候補者が働くイメージを持ちにくい状態です。',
            'recommended_contents' => ['携わっているプロジェクトの具体例', 'キャリアパスのモデルケース'],
            'mid_term_action' => '中長期的には、社員インタビューの連載化も検討できます。',
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame('まずは既存情報だけで追加できる仕事内容・キャリア情報から充実させましょう。', $viewModel->improvementOnePoint);
        $this->assertSame('まずは仕事の魅力に関する情報を拡充することを推奨します。', $viewModel->improvementRecommendation);
        $this->assertSame('仕事の魅力は競合が複数件読み取れているのに対し自社は0件で、候補者が働くイメージを持ちにくい状態です。', $viewModel->improvementReason);
        $this->assertSame(['携わっているプロジェクトの具体例', 'キャリアパスのモデルケース'], $viewModel->improvementRecommendedContents);
        $this->assertSame('中長期的には、社員インタビューの連載化も検討できます。', $viewModel->improvementMidTermAction);
    }

    /**
     * status='error'/'pending'の提案は未生成扱いとし、決定的ロジックへ
     * フォールバックする(status='success'の行のみを採用する、
     * ReportViewModelBuilder参照)。
     */
    public function test_improvement_one_point_ignores_a_non_success_suggestion_row(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);
        BrandWheelImprovementSuggestion::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => 'error',
            'one_point' => null,
            'recommendation' => null,
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame($viewModel->brandWheelComparison['one_point']['text'], $viewModel->improvementOnePoint);
        $this->assertNull($viewModel->improvementRecommendation);
    }

    // ------------------------------------------------------------------
    // 依頼W-2(2026-08-26、B案): 競合ありの経路(improvementFocusが非null)
    // では、AIのone_point/reasonではなく$improvementFocusから決定的に
    // 組み立てた文言に差し替える。
    // ------------------------------------------------------------------

    /**
     * 候補(competitor_matched && !self_matched)が1件以上あるとき、
     * ワンポイントはAIのone_pointではなく$improvementFocus['items'][0]
     * (カード1枚目と同じ項目)から組み立てた文になり、理由(AI生成)は
     * 非表示になる。
     */
    public function test_improvement_one_point_is_replaced_with_a_deterministic_recommendation_when_competitor_focus_has_items(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, isPrimary: false);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [
                ['key' => 'purpose', 'evidence' => 'x1'], ['key' => 'business_expansion', 'evidence' => 'x2'],
                ['key' => 'project_initiative', 'evidence' => 'x3'], ['key' => 'social_contribution', 'evidence' => 'x4'],
                ['key' => 'core_values', 'evidence' => 'x5'], ['key' => 'atmosphere', 'evidence' => 'x6'],
            ]]],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'relationship', 'matched_sub_elements' => [
                ['key' => 'colleagues', 'evidence' => 'y1'], ['key' => 'atmosphere', 'evidence' => 'y2'],
                ['key' => 'physical_freedom', 'evidence' => 'y3'], ['key' => 'mental_freedom', 'evidence' => 'y4'],
                ['key' => 'core_values', 'evidence' => 'y5'], ['key' => 'leadership', 'evidence' => 'y6'],
            ]]],
        ]);
        // AIのone_point/reasonは、実際に選ばれる項目とは無関係な文言にしておく
        // ―― 差し替わっていることを確認するため。
        BrandWheelImprovementSuggestion::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => 'success',
            'one_point' => 'AIが生成した無関係な推奨文。',
            'reason' => 'AIが生成した無関係な理由。',
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNotNull($viewModel->improvementFocus);
        $this->assertNotEmpty($viewModel->improvementFocus['items']);
        $this->assertSame(
            sprintf(
                (string) config('brand_wheel.improvement_focus_templates.one_point_recommend_item'),
                $viewModel->improvementFocus['items'][0]['sub_name'],
            ),
            $viewModel->improvementOnePoint,
        );
        $this->assertNull($viewModel->improvementReason);
        $this->assertStringNotContainsString('AIが生成した', (string) $viewModel->improvementOnePoint);
    }

    /**
     * 依頼X-2/W-2: 自社が3領域すべてで競合を上回り、候補項目が1件も無い
     * とき(レポート42相当)、ワンポイントは$improvementFocus['lead_text']
     * (「まずは『X』を追加することを推奨します」が成立しないため)をそのまま
     * 使う。ページ自体は消えない($improvementFocusが非null)。
     */
    public function test_improvement_one_point_falls_back_to_the_lead_text_when_competitor_focus_has_no_candidate(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, isPrimary: false);

        // 自社が確実に競合を上回るようにする(自社は全24項目、競合は
        // そのうち一部のみ) ―― どの領域にも「競合にあり自社に無い」項目が
        // 無い状態を作る。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => collect(config('brand_wheel.axes'))->map(fn (array $axis, string $axisKey) => [
                'axis_key' => $axisKey,
                'matched_sub_elements' => collect($axis['sub_elements'])->keys()->map(fn (string $subKey) => ['key' => $subKey, 'evidence' => "{$axisKey}-{$subKey}"])->all(),
            ])->values()->all(),
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [
                ['key' => 'purpose', 'evidence' => 'y1'], ['key' => 'business_expansion', 'evidence' => 'y2'],
                ['key' => 'project_initiative', 'evidence' => 'y3'], ['key' => 'social_contribution', 'evidence' => 'y4'],
                ['key' => 'brand_recognition', 'evidence' => 'y5'], ['key' => 'competitiveness', 'evidence' => 'y6'],
            ]]],
        ]);
        BrandWheelImprovementSuggestion::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => 'success',
            'one_point' => 'AIが生成した無関係な推奨文。',
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNotNull($viewModel->improvementFocus);
        $this->assertSame([], $viewModel->improvementFocus['items']);
        $this->assertSame($viewModel->improvementFocus['lead_text'], $viewModel->improvementOnePoint);
        $this->assertNull($viewModel->improvementReason);
        // 依頼AF-3: 候補が0件(自社優位)のときは、lead_textが既に状況を
        // 説明しているため代替文言は出さない(ReportViewModelBuilder参照)。
        $this->assertNull($viewModel->improvementFallbackNote);
        $this->assertStringNotContainsString('AIが生成した', (string) $viewModel->improvementOnePoint);
        $this->assertStringNotContainsString('0件', (string) $viewModel->improvementOnePoint);
    }

    // ------------------------------------------------------------------
    // 2026-08-25追加: 診断レポートを商談で使える状態にする(修正1〜3・5)。
    // 自社/競合いずれかの合計matched件数がconfig('brand_wheel.
    // comparison_sufficiency_threshold')(既定6)未満のとき、比較に基づく
    // 主張(競合引用・優劣判定・個別提案)を出さない。
    // ------------------------------------------------------------------

    /**
     * 修正1: 競合の合計matched件数が閾値未満のとき、compose()は使わず
     * composeSelfOnly()へフォールバックする。競合引用(competitor_evidence)は
     * 一切出ない。
     */
    public function test_improvement_focus_falls_back_to_self_only_when_competitor_is_below_the_sufficiency_threshold(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, isPrimary: false);

        // レポート32相当: 自社4/24、競合1/24。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'personality', 'matched_sub_elements' => [
                ['key' => 'leadership', 'evidence' => 'a'], ['key' => 'org_structure', 'evidence' => 'b'],
                ['key' => 'company_character', 'evidence' => 'c'], ['key' => 'core_values', 'evidence' => 'd'],
            ]]],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'personality', 'matched_sub_elements' => [
                ['key' => 'core_values', 'evidence' => '当社の理念に共感してもらえる方、またIT最先端技術を追求し、新しい機能開発に打ち込みたい方は是非ご応募ください。'],
            ]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNull($viewModel->improvementFocus);
        $this->assertNotNull($viewModel->improvementFocusSelfOnly);
        foreach ($viewModel->improvementFocusSelfOnly['items'] as $item) {
            $this->assertArrayNotHasKey('competitor_evidence', $item);
        }
        $raw = json_encode([
            $viewModel->improvementFocus,
            $viewModel->improvementFocusSelfOnly,
            $viewModel->improvementReason,
            $viewModel->improvementOnePoint,
            $viewModel->improvementRecommendedContents,
        ], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('当社の理念に共感してもらえる方', $raw);
    }

    /**
     * 修正2: 自社の合計matched件数が閾値未満のとき、個別項目(reason/
     * recommended_contents/mid_term_action)の提案は出ず、one_pointは
     * config('brand_wheel.one_point_messages.insufficient_content')の
     * 定型文になる(AIが生成した値があっても使わない)。
     */
    public function test_individual_item_suggestions_are_suppressed_and_one_point_uses_the_deterministic_template_when_self_is_below_the_sufficiency_threshold(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        // 自社4/24(閾値6未満)。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'personality', 'matched_sub_elements' => [
                ['key' => 'leadership', 'evidence' => 'a'], ['key' => 'org_structure', 'evidence' => 'b'],
                ['key' => 'company_character', 'evidence' => 'c'], ['key' => 'core_values', 'evidence' => 'd'],
            ]]],
        ]);
        // AIが(閾値導入前や不具合等で)個別提案を生成済みだったとしても、
        // ここでは一切使われないこと。
        BrandWheelImprovementSuggestion::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => 'success',
            'one_point' => 'まずは『重視する価値』を具体的に伝えることを推奨します。',
            'recommendation' => 'まずは重視する価値に関する情報を拡充することを推奨します。',
            'reason' => '競合がこの点を強調しているため、御社も具体的な価値観を示すことで差を埋めることができます。',
            'recommended_contents' => ['具体的な価値観のエピソード'],
            'mid_term_action' => '中長期的には、社員インタビューの連載化も検討できます。',
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame(
            (string) config('brand_wheel.one_point_messages.insufficient_content'),
            $viewModel->improvementOnePoint,
        );
        $this->assertNull($viewModel->improvementRecommendation);
        $this->assertNull($viewModel->improvementReason);
        $this->assertSame([], $viewModel->improvementRecommendedContents);
        $this->assertNull($viewModel->improvementMidTermAction);
    }

    /**
     * 修正3: 自社・競合のいずれかが閾値未満のとき、groupTotals/
     * comparisonOverview(優劣判定・対比表のバッジの元データ)は空配列になる。
     * 件数の事実(selfTotalMatched等)自体は表示され続ける。
     */
    public function test_group_totals_and_comparison_overview_are_suppressed_when_either_side_is_below_the_sufficiency_threshold(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, isPrimary: false);

        // 自社4/24、競合1/24。1件対1件を「同程度」と判定させない
        // (実際は両方とも読めていないだけ)。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'personality', 'matched_sub_elements' => [
                ['key' => 'leadership', 'evidence' => 'a'], ['key' => 'org_structure', 'evidence' => 'b'],
                ['key' => 'company_character', 'evidence' => 'c'], ['key' => 'core_values', 'evidence' => 'd'],
            ]]],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'personality', 'matched_sub_elements' => [['key' => 'core_values', 'evidence' => 'e']]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame([], $viewModel->groupTotals);
        $this->assertSame([], $viewModel->comparisonOverview);
        // 件数そのものは引き続き表示される。
        $this->assertSame(4, $viewModel->selfTotalMatched);
        $this->assertSame(1, $viewModel->competitorTotalMatched);
    }

    /**
     * 依頼P-2(2026-08-25、依頼Oの続き): matched件数が閾値未満、かつ
     * 実際の入力文字数(input_char_count)も閾値
     * (self_low_content_notice_min_chars)未満 ―― 実際に本文が少なかった
     * ケースなので、(a)の文言(self_low_content_notice)のまま。
     */
    public function test_self_low_content_notice_is_set_only_when_self_is_below_the_sufficiency_threshold(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'input_char_count' => 500,
            'axes' => [['axis_key' => 'personality', 'matched_sub_elements' => [
                ['key' => 'leadership', 'evidence' => 'a'], ['key' => 'org_structure', 'evidence' => 'b'],
                ['key' => 'company_character', 'evidence' => 'c'], ['key' => 'core_values', 'evidence' => 'd'],
            ]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame((string) config('brand_wheel.self_low_content_notice'), $viewModel->selfLowContentNotice);
        $this->assertStringNotContainsString('情報が無い', $viewModel->selfLowContentNotice);
    }

    /**
     * 依頼O-1/P-2: input_truncated=trueは「予算上限まで本文があった」ことの
     * 証拠であり、「本文が少なかった」という但し書きの前提と矛盾する
     * (レポート34: 本文23,935字→17,945字に切り詰め、matched=5/24で
     * 但し書きが誤って表示された)。matched件数が閾値未満でも、
     * input_truncated=trueのときは(a)ではなく(b)
     * (self_low_content_notice_thin_match、「診断結果そのもの」の文言)を
     * 出すこと。
     */
    public function test_self_low_content_notice_shows_thin_match_wording_when_input_was_truncated(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'input_truncated' => true,
            'input_char_count' => 17945,
            'axes' => [['axis_key' => 'personality', 'matched_sub_elements' => [
                ['key' => 'leadership', 'evidence' => 'a'], ['key' => 'org_structure', 'evidence' => 'b'],
                ['key' => 'company_character', 'evidence' => 'c'], ['key' => 'core_values', 'evidence' => 'd'],
            ]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        // matched=4 < comparison_sufficiency_threshold(6)だが、
        // input_truncated=trueのため(a)ではなく(b)を出す。
        $this->assertSame(4, $viewModel->selfTotalMatched);
        $this->assertSame((string) config('brand_wheel.self_low_content_notice_thin_match'), $viewModel->selfLowContentNotice);
    }

    /**
     * 依頼P-2: matched件数が閾値未満だが、input_truncated=falseかつ
     * input_char_countがself_low_content_notice_min_chars(既定3000)以上
     * ―― truncationは起きていないが、本文自体は閾値以上あった場合も
     * 同じく(b)を出すこと(truncated=trueだけが(b)の条件ではない)。
     */
    public function test_self_low_content_notice_shows_thin_match_wording_when_char_count_meets_the_threshold_without_truncation(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'input_truncated' => false,
            'input_char_count' => (int) config('brand_wheel.self_low_content_notice_min_chars'),
            'axes' => [['axis_key' => 'personality', 'matched_sub_elements' => [
                ['key' => 'leadership', 'evidence' => 'a'], ['key' => 'org_structure', 'evidence' => 'b'],
                ['key' => 'company_character', 'evidence' => 'c'], ['key' => 'core_values', 'evidence' => 'd'],
            ]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame((string) config('brand_wheel.self_low_content_notice_thin_match'), $viewModel->selfLowContentNotice);
    }

    /**
     * 依頼P-2最重要: matched件数が閾値未満で、input_char_countがnull
     * (旧データ・input組み立て失敗等で判定材料が無い)のときは、(a)(b)
     * いずれの文言も出さないこと。判定材料が無いときに推測で「本文が
     * 少なかった」と断定しない(レポート34の誤りの再発防止)。
     */
    public function test_self_low_content_notice_is_null_when_char_count_is_unknown(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'input_truncated' => false,
            'input_char_count' => null,
            'axes' => [['axis_key' => 'personality', 'matched_sub_elements' => [
                ['key' => 'leadership', 'evidence' => 'a'], ['key' => 'org_structure', 'evidence' => 'b'],
                ['key' => 'company_character', 'evidence' => 'c'], ['key' => 'core_values', 'evidence' => 'd'],
            ]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame(4, $viewModel->selfTotalMatched);
        $this->assertNull($viewModel->selfLowContentNotice);
    }

    /**
     * 依頼P-2: self_low_content_notice_min_charsをconfigで差し替えたとき、
     * (a)/(b)の判定がその値に追随すること。
     */
    public function test_self_low_content_notice_threshold_follows_config(): void
    {
        config(['brand_wheel.self_low_content_notice_min_chars' => 1000]);

        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        // 変更後の閾値(1000)以上・変更前の既定値(3000)未満 ―― configを
        // 差し替えていなければ(a)になるはずの文字数で(b)になることを確認する。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'input_truncated' => false,
            'input_char_count' => 1500,
            'axes' => [['axis_key' => 'personality', 'matched_sub_elements' => [
                ['key' => 'leadership', 'evidence' => 'a'], ['key' => 'org_structure', 'evidence' => 'b'],
                ['key' => 'company_character', 'evidence' => 'c'], ['key' => 'core_values', 'evidence' => 'd'],
            ]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame((string) config('brand_wheel.self_low_content_notice_thin_match'), $viewModel->selfLowContentNotice);
    }

    public function test_self_low_content_notice_is_null_when_self_meets_the_sufficiency_threshold(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => 'x1'], ['key' => 'business_expansion', 'evidence' => 'x2'],
                    ['key' => 'project_initiative', 'evidence' => 'x3'], ['key' => 'social_contribution', 'evidence' => 'x4'],
                ]],
                ['axis_key' => 'asset', 'matched_sub_elements' => [
                    ['key' => 'brand_recognition', 'evidence' => 'x5'], ['key' => 'competitiveness', 'evidence' => 'x6'],
                ]],
            ],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNull($viewModel->selfLowContentNotice);
    }

    /**
     * レポート32(2026-08-24、自社=NTTデータ4/24、競合=しんきん1/24)と
     * 同じ条件のフィクスチャ。修正後、商談で言えない文章
     * (「競合がこの点を強調しているため」等)が一切出ないことを確認する。
     */
    public function test_report_32_fixture_no_longer_produces_a_comparison_based_claim_from_thin_data(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社NTTデータ']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, isPrimary: false);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            // 依頼P-2: このフィクスチャは実際に本文が薄かったケース
            // (レポート32、NTTデータ4/24)を再現するため、
            // self_low_content_notice_min_chars(既定3000)未満の文字数を
            // 指定し、(a)の文言が出ることを確認する。
            'input_char_count' => 800,
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => '技術で社会基盤を支える']]],
                ['axis_key' => 'personality', 'matched_sub_elements' => [['key' => 'core_values', 'evidence' => '挑戦を続ける']]],
                ['axis_key' => 'financial_benefit', 'matched_sub_elements' => [
                    ['key' => 'salary_level', 'evidence' => '給与水準の記述'], ['key' => 'benefits', 'evidence' => '福利厚生の記述'],
                ]],
            ],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'personality', 'matched_sub_elements' => [
                ['key' => 'core_values', 'evidence' => '当社の理念に共感してもらえる方、またIT最先端技術を追求し、新しい機能開発に打ち込みたい方は是非ご応募ください。'],
            ]]],
        ]);
        BrandWheelImprovementSuggestion::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => 'success',
            'one_point' => 'まずは『重視する価値』を具体的に伝えることを推奨します。',
            'reason' => '候補者は御社の価値観を理解することで応募意欲が高まります。競合がこの点を強調しているため、御社も具体的な価値観を示すことで差を埋めることができます。',
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame(4, $viewModel->selfTotalMatched);
        $this->assertSame(1, $viewModel->competitorTotalMatched);

        // 個別項目の提案(閾値未満のAI reasonを含む)は一切出ない。
        $this->assertNull($viewModel->improvementReason);
        $this->assertNotEquals('まずは『重視する価値』を具体的に伝えることを推奨します。', $viewModel->improvementOnePoint);
        // 競合の引用付きcompose()は使わない。
        $this->assertNull($viewModel->improvementFocus);
        // 優劣判定は出ない。
        $this->assertSame([], $viewModel->groupTotals);
        $this->assertSame([], $viewModel->comparisonOverview);
        // 但し書きが出る。
        $this->assertNotNull($viewModel->selfLowContentNotice);

        $raw = json_encode([
            $viewModel->improvementFocus,
            $viewModel->improvementFocusSelfOnly,
            $viewModel->improvementOnePoint,
            $viewModel->improvementReason,
            $viewModel->improvementRecommendation,
            $viewModel->improvementRecommendedContents,
            $viewModel->improvementMidTermAction,
            $viewModel->groupTotals,
            $viewModel->comparisonOverview,
        ], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('競合がこの点を強調している', $raw);
        $this->assertStringNotContainsString('当社の理念に共感してもらえる方', $raw);
        $this->assertStringNotContainsString('差を埋める', $raw);
    }

    /**
     * 依頼R(2026-08-26): matchedが6件(複数軸にまたがる)のとき、
     * 6件すべてがselfEvidenceByAxisに軸ごと・対比表と同じ順序で並び、
     * evidenceがそのまま(要約・改変なし)含まれること。
     */
    public function test_self_evidence_by_axis_groups_matched_items_by_axis_in_config_order(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => 'パーパスの原文抜粋です。'],
                    ['key' => 'business_expansion', 'evidence' => '事業内容の原文抜粋です。'],
                ]],
                ['axis_key' => 'asset', 'matched_sub_elements' => [
                    ['key' => 'brand_recognition', 'evidence' => '知名度の原文抜粋です。'],
                ]],
                ['axis_key' => 'personality', 'matched_sub_elements' => [
                    ['key' => 'leadership', 'evidence' => 'リーダーシップの原文抜粋です。'],
                ]],
                ['axis_key' => 'relationship', 'matched_sub_elements' => [
                    ['key' => 'colleagues', 'evidence' => '同僚・先輩像の原文抜粋です。'],
                ]],
                ['axis_key' => 'financial_benefit', 'matched_sub_elements' => [
                    ['key' => 'salary_level', 'evidence' => '給与水準の原文抜粋です。'],
                ]],
            ],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame(6, $viewModel->selfTotalMatched);
        // config('brand_wheel.axes')の並び順(will_activity→asset→personality→
        // relationship→...→financial_benefit)のまま5軸ぶんが並ぶこと。
        $axisOrder = array_column($viewModel->selfEvidenceByAxis, 'axis_name');
        $this->assertSame(['活動的魅力', '資産的魅力', '経営スタイル', '就業環境', '金銭的便益'], $axisOrder);

        $totalItems = array_sum(array_map(fn (array $g) => count($g['items']), $viewModel->selfEvidenceByAxis));
        $this->assertSame(6, $totalItems);

        // will_activity軸は2件、対比表と同じ下位要素順(purpose→business_expansion)。
        $willActivityItems = $viewModel->selfEvidenceByAxis[0]['items'];
        $this->assertCount(2, $willActivityItems);
        $this->assertSame('パーパス', $willActivityItems[0]['sub_name']);
        $this->assertSame('パーパスの原文抜粋です。', $willActivityItems[0]['evidence']);
        $this->assertSame('展開事業・商品', $willActivityItems[1]['sub_name']);
        $this->assertSame('事業内容の原文抜粋です。', $willActivityItems[1]['evidence']);
    }

    /**
     * 依頼R: evidenceが空文字の項目は、その項目ごとselfEvidenceByAxisに
     * 含まれないこと(空の引用符だけが並ぶ状態を作らない)。
     */
    public function test_self_evidence_by_axis_omits_items_with_empty_evidence(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => 'パーパスの原文抜粋です。'],
                    ['key' => 'business_expansion', 'evidence' => ''],
                ]],
            ],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $items = $viewModel->selfEvidenceByAxis[0]['items'];
        $this->assertCount(1, $items);
        $this->assertSame('パーパス', $items[0]['sub_name']);
    }

    /**
     * 依頼R最重要: matchedが0件のとき、selfEvidenceByAxisは空配列になり
     * (呼び出し側=Blade/WordReportGeneratorはこの場合ページ自体を出さない)。
     */
    public function test_self_evidence_by_axis_is_empty_when_self_has_no_matched_items(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame(0, $viewModel->selfTotalMatched);
        $this->assertSame([], $viewModel->selfEvidenceByAxis);
    }

    /**
     * 依頼R: 競合サイトの引用は一切含まれないこと(第三者の文章のため)。
     */
    public function test_self_evidence_by_axis_never_includes_competitor_evidence(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, isPrimary: false);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [
                ['key' => 'purpose', 'evidence' => '自社サイトの原文抜粋です。'],
            ]]],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [
                ['key' => 'purpose', 'evidence' => '競合サイトの原文抜粋です。これが出てはならない。'],
            ]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $raw = json_encode($viewModel->selfEvidenceByAxis, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('自社サイトの原文抜粋です。', $raw);
        $this->assertStringNotContainsString('競合サイトの原文抜粋です', $raw);
    }

    /**
     * 依頼R: discarded_sub_elements(AIが挙げた引用が原文照合で棄却された
     * もの、evidence_not_found等)の内容は一切参照しないこと。
     */
    public function test_self_evidence_by_axis_never_includes_discarded_sub_elements(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [
                ['key' => 'purpose', 'evidence' => '実在する原文抜粋です。'],
            ], 'discarded_sub_elements' => [
                ['key' => 'business_expansion', 'reason' => 'evidence_not_found', 'evidence' => 'AIが捏造した抜粋、これが出てはならない。'],
            ]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $raw = json_encode($viewModel->selfEvidenceByAxis, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('AIが捏造した抜粋', $raw);
    }

    /**
     * 依頼R: 引用が長い場合、config('brand_wheel.evidence_page_quote_max_chars')で
     * BrandWheelTextTruncator::truncateAtSentenceBoundary()により切り詰められる
     * (文の途中で切らない、句点が上限内に見つかればそこで止める)。
     */
    public function test_self_evidence_by_axis_truncates_long_evidence_at_sentence_boundary(): void
    {
        config(['brand_wheel.evidence_page_quote_max_chars' => 20]);

        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        // 20文字以内に句点がある場合 ―― 句点まで残し、「…」は付かない。
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [
                ['key' => 'purpose', 'evidence' => '短い一文です。ここから先は上限を超える長い続きの文章です。'],
            ]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $evidence = $viewModel->selfEvidenceByAxis[0]['items'][0]['evidence'];
        $this->assertSame('短い一文です。', $evidence);
        $this->assertStringNotContainsString('…', $evidence);

        // 句点が無い場合 ―― 上限で切り、末尾に「…」を付ける。
        // Website.is_primaryはproject単位で高々1件のため、別のProjectを使う
        // (1つのProjectを使い回すと一意制約違反になる)。
        $project2 = new Project(['name' => 'テスト2']);
        $project2->user_id = $project->user_id;
        $project2->lead_session_id = $leadSession->id;
        $project2->save();
        $analysis2 = Analysis::factory()->create(['project_id' => $project2->id, 'status' => AnalysisStatus::Completed]);
        $selfWa2 = $this->makeWebsiteAnalysis($analysis2, isPrimary: true);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis2->id,
            'website_analysis_id' => $selfWa2->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [
                ['key' => 'purpose', 'evidence' => str_repeat('あ', 40)],
            ]]],
        ]);

        $viewModel2 = app(ReportViewModelBuilder::class)->build($analysis2, $leadSession);
        $evidence2 = $viewModel2->selfEvidenceByAxis[0]['items'][0]['evidence'];
        $this->assertSame(str_repeat('あ', 20).'…', $evidence2);
    }

    // ------------------------------------------------------------------
    // 依頼AA(2026-08-27): 日本語でない引用への日本語訳併記。
    // ------------------------------------------------------------------

    /**
     * 英語の引用には訳が付き、日本語の引用には付かないこと。原文
     * (evidence自体)は一切変わらないこと。
     */
    public function test_self_evidence_translation_is_attached_only_for_non_japanese_quotes(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [
                ['key' => 'purpose', 'evidence' => 'We contribute to building a better society.'],
                ['key' => 'business_expansion', 'evidence' => '地域社会への貢献を大切にしています。'],
            ]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $items = collect($viewModel->selfEvidenceByAxis[0]['items'])->keyBy('evidence');

        $english = $items['We contribute to building a better society.'];
        $this->assertSame('We contribute to building a better society.', $english['evidence']);
        $this->assertNotNull($english['evidence_translation']);

        $japanese = $items['地域社会への貢献を大切にしています。'];
        $this->assertSame('地域社会への貢献を大切にしています。', $japanese['evidence']);
        $this->assertNull($japanese['evidence_translation']);

        $this->assertTrue($viewModel->hasQuoteTranslations);
    }

    /**
     * 依頼AA-2の必須要件: 引用がすべて日本語のレポートでは、AI呼び出しが
     * 一切発生しないこと(services.brand_wheel_ai.providerをopenaiにして、
     * 実際にHTTPリクエストが送られないことを確認する)。
     */
    public function test_no_ai_call_is_made_when_all_quotes_are_japanese(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        \Illuminate\Support\Facades\Http::fake();

        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [
                ['key' => 'purpose', 'evidence' => '地域社会への貢献を大切にしています。'],
            ]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertFalse($viewModel->hasQuoteTranslations);
        $this->assertNull($viewModel->selfEvidenceByAxis[0]['items'][0]['evidence_translation']);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    /**
     * 競合サイトの引用(改善提案ページ)にも同じ方針が適用されること
     * (依頼AA-1: 洗い出した全箇所に一貫して適用する)。
     */
    public function test_competitor_evidence_translation_is_attached_for_non_japanese_quotes(): void
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, isPrimary: false);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => '自社の抜粋']]]],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'relationship', 'matched_sub_elements' => [
                    ['key' => 'colleagues', 'evidence' => 'Meet our diverse team members from around the world.'],
                    ['key' => 'atmosphere', 'evidence' => '雰囲気についての競合サイトの抜粋'],
                ]],
                ['axis_key' => 'asset', 'matched_sub_elements' => [
                    ['key' => 'brand_recognition', 'evidence' => 'y1'], ['key' => 'competitiveness', 'evidence' => 'y2'],
                ]],
                ['axis_key' => 'financial_benefit', 'matched_sub_elements' => [
                    ['key' => 'salary_level', 'evidence' => 'y3'], ['key' => 'benefits', 'evidence' => 'y4'],
                ]],
            ],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertNotNull($viewModel->improvementFocus);
        $items = collect($viewModel->improvementFocus['items'])->keyBy('competitor_evidence');

        $english = $items['Meet our diverse team members from around the world.'];
        $this->assertSame('Meet our diverse team members from around the world.', $english['competitor_evidence']);
        $this->assertNotNull($english['competitor_evidence_translation']);

        $japanese = $items['雰囲気についての競合サイトの抜粋'];
        $this->assertNull($japanese['competitor_evidence_translation']);

        $this->assertTrue($viewModel->hasQuoteTranslations);
    }

    /**
     * 依頼AA-3の必須要件(最重要): 翻訳の呼び出しが失敗しても、レポートの
     * 生成そのものは成功し、原文だけが表示されること。
     */
    public function test_report_building_succeeds_with_original_text_only_when_translation_fails(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        \Illuminate\Support\Facades\Http::fake(['api.openai.com/*' => \Illuminate\Support\Facades\Http::response(['error' => 'boom'], 500)]);

        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Completed]);
        $selfWa = $this->makeWebsiteAnalysis($analysis, isPrimary: true);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [
                ['key' => 'purpose', 'evidence' => 'We contribute to building a better society.'],
            ]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame('We contribute to building a better society.', $viewModel->selfEvidenceByAxis[0]['items'][0]['evidence']);
        $this->assertNull($viewModel->selfEvidenceByAxis[0]['items'][0]['evidence_translation']);
        $this->assertFalse($viewModel->hasQuoteTranslations);
    }
}
