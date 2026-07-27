<?php

namespace Tests\Unit\Jobs\Report;

use App\Enums\ReportGenerationStatus;
use App\Jobs\Report\GenerateLeadReportJob;
use App\Models\Analysis;
use App\Models\LeadSession;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Report\PdfReportGenerator;
use App\Services\Report\ReportViewModelBuilder;
use App\Services\Report\WordReportGenerator;
use Database\Seeders\CategoryDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateLeadReportJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategoryDefinitionSeeder::class);
        Storage::fake('analysis');
    }

    private function makeLeadAnalysis(): Analysis
    {
        $leadSession = LeadSession::factory()->create(['company_name' => '株式会社サンプル']);
        $project = new Project(['name' => 'テスト']);
        $project->user_id = User::factory()->create()->id;
        $project->lead_session_id = $leadSession->id;
        $project->save();

        $analysis = Analysis::factory()->create(['project_id' => $project->id]);
        $website = Website::factory()->create(['project_id' => $project->id, 'is_primary' => true]);
        WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        return $analysis;
    }

    public function test_it_generates_both_formats_and_stores_them(): void
    {
        $analysis = $this->makeLeadAnalysis();

        (new GenerateLeadReportJob($analysis->id))->handle(
            app(ReportViewModelBuilder::class),
            app(WordReportGenerator::class),
            app(PdfReportGenerator::class),
        );

        $docxReport = Report::where('analysis_id', $analysis->id)->where('format', 'docx')->first();
        $pdfReport = Report::where('analysis_id', $analysis->id)->where('format', 'pdf')->first();

        $this->assertNotNull($docxReport);
        $this->assertNotNull($pdfReport);
        $this->assertSame(ReportGenerationStatus::Completed, $docxReport->status);
        $this->assertSame(ReportGenerationStatus::Completed, $pdfReport->status);
        Storage::disk('analysis')->assertExists($docxReport->storage_path);
        Storage::disk('analysis')->assertExists($pdfReport->storage_path);
    }

    public function test_it_is_idempotent_and_does_not_regenerate_an_already_completed_format(): void
    {
        $analysis = $this->makeLeadAnalysis();
        Storage::disk('analysis')->put("reports/{$analysis->id}/report.docx", 'existing-bytes');
        Report::factory()->create([
            'analysis_id' => $analysis->id,
            'format' => 'docx',
            'storage_path' => "reports/{$analysis->id}/report.docx",
            'status' => ReportGenerationStatus::Completed,
            'generated_at' => now(),
        ]);

        (new GenerateLeadReportJob($analysis->id))->handle(
            app(ReportViewModelBuilder::class),
            app(WordReportGenerator::class),
            app(PdfReportGenerator::class),
        );

        $this->assertSame('existing-bytes', Storage::disk('analysis')->get("reports/{$analysis->id}/report.docx"));
        $this->assertSame(1, Report::where('analysis_id', $analysis->id)->where('format', 'docx')->count());
    }

    public function test_it_does_nothing_for_an_analysis_without_a_lead_session(): void
    {
        $project = Project::factory()->create();
        $analysis = Analysis::factory()->create(['project_id' => $project->id]);

        (new GenerateLeadReportJob($analysis->id))->handle(
            app(ReportViewModelBuilder::class),
            app(WordReportGenerator::class),
            app(PdfReportGenerator::class),
        );

        $this->assertSame(0, Report::where('analysis_id', $analysis->id)->count());
    }

    public function test_it_does_nothing_for_a_deleted_analysis(): void
    {
        (new GenerateLeadReportJob(999999))->handle(
            app(ReportViewModelBuilder::class),
            app(WordReportGenerator::class),
            app(PdfReportGenerator::class),
        );

        $this->assertSame(0, Report::count());
    }
}
