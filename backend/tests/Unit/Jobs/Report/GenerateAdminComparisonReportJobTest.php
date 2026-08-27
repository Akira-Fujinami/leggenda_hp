<?php

namespace Tests\Unit\Jobs\Report;

use App\Enums\AnalysisStatus;
use App\Enums\ReportGenerationStatus;
use App\Jobs\Report\GenerateAdminComparisonReportJob;
use App\Models\Analysis;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Report\AdminComparisonPdfGenerator;
use App\Services\Report\MultiSiteReportViewModelBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼AC(2026-08-27): 管理者向け多社比較レポート(PDFのみ)のJob。
 * GenerateLeadReportJobTestと同じ方針(handle()を直接呼ぶ、Storage::fake)。
 */
class GenerateAdminComparisonReportJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
    }

    /**
     * @param  int  $competitorCount  自社以外に作るWebsite数(最低1でcompose()が例外を投げないようにする)
     */
    private function makeComparisonAnalysis(int $competitorCount = 3): Analysis
    {
        $company = LeadCompany::factory()->create();

        $sourceProject = new Project(['name' => '起点']);
        $sourceProject->user_id = User::factory()->create()->id;
        $sourceProject->lead_company_id = $company->id;
        $sourceProject->save();
        $sourceAnalysis = Analysis::factory()->create(['project_id' => $sourceProject->id, 'status' => AnalysisStatus::Completed]);

        $project = new Project(['name' => '比較']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_company_id = $company->id;
        $project->save();

        $analysis = Analysis::factory()->create([
            'project_id' => $project->id,
            'status' => AnalysisStatus::Completed,
            'source_analysis_id' => $sourceAnalysis->id,
        ]);

        $selfWebsite = Website::factory()->create(['project_id' => $project->id, 'is_primary' => true, 'display_order' => 0, 'name' => '自社サイト']);
        WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $selfWebsite->id]);

        for ($i = 0; $i < $competitorCount; $i++) {
            $competitorWebsite = Website::factory()->create([
                'project_id' => $project->id,
                'is_primary' => false,
                'display_order' => $i + 1,
                'name' => "競合{$i}",
            ]);
            WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $competitorWebsite->id]);
        }

        return $analysis;
    }

    public function test_it_generates_the_pdf_and_stores_it(): void
    {
        $analysis = $this->makeComparisonAnalysis();

        (new GenerateAdminComparisonReportJob($analysis->id))->handle(
            app(MultiSiteReportViewModelBuilder::class),
            app(AdminComparisonPdfGenerator::class),
        );

        $report = Report::where('analysis_id', $analysis->id)->where('format', 'pdf')->first();

        $this->assertNotNull($report);
        $this->assertSame(ReportGenerationStatus::Completed, $report->status);
        Storage::disk('analysis')->assertExists($report->storage_path);
    }

    public function test_it_is_idempotent_and_does_not_regenerate_an_already_completed_report(): void
    {
        $analysis = $this->makeComparisonAnalysis();
        Storage::disk('analysis')->put("reports/{$analysis->id}/admin-comparison-report.pdf", 'existing-bytes');
        Report::factory()->create([
            'analysis_id' => $analysis->id,
            'format' => 'pdf',
            'storage_path' => "reports/{$analysis->id}/admin-comparison-report.pdf",
            'status' => ReportGenerationStatus::Completed,
            'generated_at' => now(),
        ]);

        (new GenerateAdminComparisonReportJob($analysis->id))->handle(
            app(MultiSiteReportViewModelBuilder::class),
            app(AdminComparisonPdfGenerator::class),
        );

        $this->assertSame('existing-bytes', Storage::disk('analysis')->get("reports/{$analysis->id}/admin-comparison-report.pdf"));
        $this->assertSame(1, Report::where('analysis_id', $analysis->id)->where('format', 'pdf')->count());
    }

    /**
     * 二重の安全弁(依頼AC): 比較Analysisでない(source_analysis_idがnull)
     * 場合は、通常のリード診断に多社比較レポートを生成しない。
     */
    public function test_it_does_nothing_for_an_analysis_that_is_not_a_comparison(): void
    {
        $project = Project::factory()->create();
        $analysis = Analysis::factory()->create(['project_id' => $project->id]);

        (new GenerateAdminComparisonReportJob($analysis->id))->handle(
            app(MultiSiteReportViewModelBuilder::class),
            app(AdminComparisonPdfGenerator::class),
        );

        $this->assertSame(0, Report::where('analysis_id', $analysis->id)->count());
    }

    public function test_it_does_nothing_for_a_deleted_analysis(): void
    {
        (new GenerateAdminComparisonReportJob(999999))->handle(
            app(MultiSiteReportViewModelBuilder::class),
            app(AdminComparisonPdfGenerator::class),
        );

        $this->assertSame(0, Report::count());
    }

    /**
     * 依頼AC-5: 依頼Y-1と同じ形式・恒久ログで実測ピークメモリを残す。
     * 本文・引用・URLを含まない(数値フィールドのみ)ことも確認する。
     */
    public function test_it_logs_peak_memory_usage(): void
    {
        $analysis = $this->makeComparisonAnalysis();

        Log::spy();

        (new GenerateAdminComparisonReportJob($analysis->id))->handle(
            app(MultiSiteReportViewModelBuilder::class),
            app(AdminComparisonPdfGenerator::class),
        );

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($analysis) {
                return $message === 'Admin comparison report generation peak memory usage'
                    && $context['analysis_id'] === $analysis->id
                    && $context['format'] === 'pdf'
                    && is_int($context['memory_before_bytes'])
                    && is_int($context['memory_peak_bytes'])
                    && $context['memory_peak_bytes'] > 0
                    && array_keys($context) === ['analysis_id', 'format', 'memory_before_bytes', 'memory_peak_bytes'];
            })
            ->once();
    }
}
