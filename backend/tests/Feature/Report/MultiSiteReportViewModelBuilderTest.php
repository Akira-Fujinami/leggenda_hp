<?php

namespace Tests\Feature\Report;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Report\AdminComparisonPptxDataBuilder;
use App\Services\Report\MultiSiteReportViewModelBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 依頼AC: MultiSiteReportViewModelBuilder(自社1×競合N社専用、既存の
 * ReportViewModelBuilderは無改修)。ReportViewModelBuilderTestと同じ方針
 * (実DB・実Composerを通す、翻訳はテスト既定のmockプロバイダを使う)。
 */
class MultiSiteReportViewModelBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function makeComparisonAnalysis(): Analysis
    {
        $company = LeadCompany::factory()->create(['company_name' => '株式会社サンプル']);

        $sourceProject = new Project(['name' => '起点']);
        $sourceProject->user_id = User::factory()->create()->id;
        $sourceProject->lead_company_id = $company->id;
        $sourceProject->save();
        $sourceAnalysis = Analysis::factory()->create(['project_id' => $sourceProject->id, 'status' => AnalysisStatus::Completed]);

        $project = new Project(['name' => '比較']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_company_id = $company->id;
        $project->save();

        return Analysis::factory()->create([
            'project_id' => $project->id,
            'status' => AnalysisStatus::Completed,
            'source_analysis_id' => $sourceAnalysis->id,
        ]);
    }

    private function addSite(Analysis $analysis, bool $isPrimary, int $displayOrder, string $name, array $axes): WebsiteAnalysis
    {
        $website = Website::factory()->create([
            'project_id' => $analysis->project_id,
            'is_primary' => $isPrimary,
            'display_order' => $displayOrder,
            'name' => $name,
            'url' => "https://{$name}.example.com",
        ]);
        $wa = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $wa->id,
            'status' => 'success',
            'axes' => $axes,
        ]);

        return $wa;
    }

    public function test_competitors_are_ordered_by_display_order_regardless_of_creation_order(): void
    {
        $analysis = $this->makeComparisonAnalysis();
        $this->addSite($analysis, true, 0, 'self', []);
        // わざと作成順をdisplay_order順と逆にする。
        $this->addSite($analysis, false, 2, 'second', []);
        $this->addSite($analysis, false, 1, 'first', []);
        $this->addSite($analysis, false, 3, 'third', []);

        $viewModel = app(MultiSiteReportViewModelBuilder::class)->build($analysis->fresh());

        $this->assertSame(['first', 'second', 'third'], array_column($viewModel->competitors, 'name'));
    }

    /**
     * 依頼AC-3最重要: 代表競合はdisplay_orderが最も早い、該当する1社
     * (決定的)。
     */
    public function test_representative_competitor_is_the_earliest_display_order_match(): void
    {
        $analysis = $this->makeComparisonAnalysis();
        $this->addSite($analysis, true, 0, 'self', []);
        // will_activity.purposeに該当しない競合(display_order=1)。
        $this->addSite($analysis, false, 1, 'alpha', []);
        // will_activity.purposeに該当する競合(display_order=2、最も早い一致)。
        $this->addSite($analysis, false, 2, 'beta', [
            ['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'betaの記述']]],
        ]);
        // will_activity.purposeに該当する競合(display_order=3)。
        $this->addSite($analysis, false, 3, 'gamma', [
            ['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'gammaの記述']]],
        ]);

        $viewModel = app(MultiSiteReportViewModelBuilder::class)->build($analysis->fresh());

        $purposeItem = collect($viewModel->missingFromSelf)->firstWhere('sub_name', 'パーパス');
        $this->assertNotNull($purposeItem);
        $this->assertSame('beta', $purposeItem['representative_company_name']);
        $this->assertSame('betaの記述', $purposeItem['quote']);
    }

    public function test_self_evidence_by_axis_only_includes_items_self_matched(): void
    {
        $analysis = $this->makeComparisonAnalysis();
        $this->addSite($analysis, true, 0, 'self', [
            ['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => '自社の抜粋です。']]],
        ]);
        $this->addSite($analysis, false, 1, 'alpha', []);
        $this->addSite($analysis, false, 2, 'beta', []);
        $this->addSite($analysis, false, 3, 'gamma', []);

        $viewModel = app(MultiSiteReportViewModelBuilder::class)->build($analysis->fresh());

        $this->assertCount(1, $viewModel->selfEvidenceByAxis);
        $this->assertSame('自社の抜粋です。', $viewModel->selfEvidenceByAxis[0]['items'][0]['evidence']);
    }

    /**
     * 依頼AA(既存方針の踏襲): 非日本語の引用には日本語訳を併記する。
     */
    public function test_non_japanese_representative_quote_gets_a_translation(): void
    {
        $analysis = $this->makeComparisonAnalysis();
        $this->addSite($analysis, true, 0, 'self', []);
        $this->addSite($analysis, false, 1, 'alpha', [
            ['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'We build a better society for everyone.']]],
        ]);
        $this->addSite($analysis, false, 2, 'beta', [
            ['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'We build a better society for everyone.']]],
        ]);
        $this->addSite($analysis, false, 3, 'gamma', []);

        $viewModel = app(MultiSiteReportViewModelBuilder::class)->build($analysis->fresh());

        $purposeItem = collect($viewModel->missingFromSelf)->firstWhere('sub_name', 'パーパス');
        $this->assertSame('alpha', $purposeItem['representative_company_name']);
        $this->assertNotNull($purposeItem['quote_translation']);
        $this->assertTrue($viewModel->hasQuoteTranslations);
    }

    public function test_majority_threshold_and_competitor_count_reflect_the_actual_number_of_competitors(): void
    {
        $analysis = $this->makeComparisonAnalysis();
        $this->addSite($analysis, true, 0, 'self', []);
        $this->addSite($analysis, false, 1, 'a', []);
        $this->addSite($analysis, false, 2, 'b', []);
        $this->addSite($analysis, false, 3, 'c', []);
        $this->addSite($analysis, false, 4, 'd', []);

        $viewModel = app(MultiSiteReportViewModelBuilder::class)->build($analysis->fresh());

        $this->assertSame(4, $viewModel->competitorCount);
        $this->assertSame(3, $viewModel->majorityThreshold);
    }

    /**
     * 依頼CD-1(2026-09-28)の再現テスト: 実物の資料で観測された「自社だけ
     * 総合が0/0になる」不具合の、コードで確認した発生機序をそのまま
     * 再現する。
     *
     * 発生機序(CD-1調査で確認): 自社の指定URLがそれ自体「採用ページ」と
     * 認識されるサイト(例: .../recruit/index.html、HtmlSeoAnalyzer::
     * isRecruitPageUrl()が'recruit'セグメントで判定)では、自己参照検出
     * (依頼AC由来の既存の正しい設計、この依頼では変更しない)により
     * 採用ページ本文が意図的に空になり(BrandWheelAnalysisInputFactory::
     * build())、ホームページ本文だけが入力になる。索引的なページで本文が
     * 薄い場合、insufficient_input_min_total_chars未満となりAIが一度も
     * 呼ばれないままstatus=insufficient_input・axes=nullで確定する
     * (GenerateBrandWheelAnalysisJob::isInputInsufficient())。これは
     * ディスパッチやcrawlの不具合ではなく正当な終了状態のため、この
     * BrandWheelAnalysisResultの状態そのものはこのテストで直接作る
     * (自己参照検出の機序自体は各層の既存テスト
     * 〈HtmlSeoAnalyzerTest/BrandWheelAnalysisInputFactoryTest/
     * FetchRecruitPageJobTest〉で別途担保済み、ここでは
     * 「この状態になったとき、比較の集計層が壊れないこと」を検証する)。
     *
     * is_primary/ディスパッチ/引き継ぎが原因ではないこと(CD-1調査で除外
     * 済み)の確認も兼ねる: 自社・競合は addSite() 経由で全く同じ経路
     * (新規Website→新規WebsiteAnalysis→BrandWheelAnalysisResult)で
     * 作られており、特別扱いは一切していない。
     */
    public function test_self_is_marked_unreadable_when_its_brand_wheel_judgment_ends_in_a_non_success_status(): void
    {
        $analysis = $this->makeComparisonAnalysis();

        $selfWebsite = Website::factory()->create([
            'project_id' => $analysis->project_id,
            'is_primary' => true,
            'display_order' => 0,
            'name' => 'self',
            'url' => 'https://www.shinkin.co.jp/ssc/recruit/index.html',
        ]);
        $selfWa = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $selfWebsite->id]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'insufficient_input',
            'axes' => null,
        ]);

        // 競合は正常に判定できている(実物の資料の観測事実どおり)。
        $this->addSite($analysis, false, 1, 'a', [
            ['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'aの記述']]],
        ]);
        $this->addSite($analysis, false, 2, 'b', [
            ['axis_key' => 'asset', 'matched_sub_elements' => [['key' => 'scale', 'evidence' => 'bの記述']]],
        ]);

        $viewModel = app(MultiSiteReportViewModelBuilder::class)->build($analysis->fresh());

        $this->assertFalse($viewModel->selfReadable, '自社の判定が非successのとき、selfReadableはfalseになること');
        $this->assertSame(0, $viewModel->selfTotalMatched);
        $this->assertSame(0, $viewModel->selfTotalMax);
        // 競合は無関係に正常な値のままであること(自社の状態が競合側へ
        // 波及しないこと)。
        $this->assertNotEmpty($viewModel->competitors);
        $this->assertNotEmpty($viewModel->comparisonTable);

        // 依頼CD-2/CD-3必須: この状態のViewModelを差し込みデータへ変換
        // しても、自社の総合が「0/0」にならない(競合と同じ24)こと、かつ
        // self_readable=falseが伝わること ―― CD-1〜CD-3を通した全体の
        // 挙動として、実物の資料で観測された「0/0」が再現しないことの確認。
        $data = app(AdminComparisonPptxDataBuilder::class)->build($viewModel);
        $this->assertFalse($data['self_readable']);
        $this->assertSame(24, $data['companies'][0]['total']);
        $this->assertNotSame(0, $data['companies'][0]['total']);
    }

    /**
     * 対照実験: 自社の判定がsuccessで、24項目中どれか一致していれば
     * selfReadable=trueになること(非退行の確認)。
     */
    public function test_self_is_marked_readable_when_its_brand_wheel_judgment_succeeds(): void
    {
        $analysis = $this->makeComparisonAnalysis();
        $this->addSite($analysis, true, 0, 'self', [
            ['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => '自社の記述']]],
        ]);
        $this->addSite($analysis, false, 1, 'a', []);

        $viewModel = app(MultiSiteReportViewModelBuilder::class)->build($analysis->fresh());

        $this->assertTrue($viewModel->selfReadable);
        $this->assertSame(1, $viewModel->selfTotalMatched);
    }

    /**
     * 依頼CH追補-1(2026-10-01)必須: selfMaterialSufficient/
     * competitorsMaterialSufficientは、input_char_count(起点ページ本文＋
     * クロール全件＋改行を含む合計)ではなくinput_origin_chars(起点由来の
     * 文字数)を見ること。この2つの値をあえて食い違わせ(input_char_countは
     * 閾値以上・input_origin_charsは閾値未満)、正しい列が参照されている
     * ことを区別して検証する。
     */
    public function test_self_material_sufficiency_is_derived_from_input_origin_chars_not_input_char_count(): void
    {
        config(['brand_wheel.insufficient_material_display_min_chars' => 3000]);

        $analysis = $this->makeComparisonAnalysis();
        $selfWa = WebsiteAnalysis::factory()->create([
            'analysis_id' => $analysis->id,
            'website_id' => Website::factory()->create([
                'project_id' => $analysis->project_id,
                'is_primary' => true,
                'display_order' => 0,
                'name' => 'self',
            ])->id,
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [],
            // input_char_countは閾値以上(信金のように起点ページ本文込みの
            // 合計は大きいが)、input_origin_charsは閾値未満(起点URL配下の
            // 材料そのものは薄い)という、信金と同じ食い違いを再現する。
            'input_char_count' => 4000,
            'input_origin_chars' => 1131,
        ]);
        $this->addSite($analysis, false, 1, 'a', []);

        $viewModel = app(MultiSiteReportViewModelBuilder::class)->build($analysis->fresh());

        $this->assertFalse(
            $viewModel->selfMaterialSufficient,
            'input_origin_chars(1,131)が閾値(3,000)未満のため、input_char_count(4,000)の値に関わらずfalseになること',
        );
    }
}
