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
        $this->assertCount(2, $viewModel->improvementFocus['items']);
        $evidences = array_column($viewModel->improvementFocus['items'], 'competitor_evidence');
        $this->assertContains('同僚についての競合サイトの抜粋', $evidences);
        $this->assertContains('雰囲気についての競合サイトの抜粋', $evidences);
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
     * 修正5: 自社の合計matched件数が閾値未満のとき、件数の近くに添える
     * 但し書きがViewModelに設定される。閾値以上のときはnull。
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
            'axes' => [['axis_key' => 'personality', 'matched_sub_elements' => [
                ['key' => 'leadership', 'evidence' => 'a'], ['key' => 'org_structure', 'evidence' => 'b'],
                ['key' => 'company_character', 'evidence' => 'c'], ['key' => 'core_values', 'evidence' => 'd'],
            ]]],
        ]);

        $viewModel = app(ReportViewModelBuilder::class)->build($analysis, $leadSession);

        $this->assertSame((string) config('brand_wheel.self_low_content_notice'), $viewModel->selfLowContentNotice);
        $this->assertStringNotContainsString('情報が無い', $viewModel->selfLowContentNotice);
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
}
