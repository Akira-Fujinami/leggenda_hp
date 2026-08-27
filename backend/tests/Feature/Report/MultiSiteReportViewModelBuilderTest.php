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
}
