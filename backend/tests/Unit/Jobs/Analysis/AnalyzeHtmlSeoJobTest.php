<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Enums\PageType;
use App\Jobs\Analysis\AnalyzeHtmlSeoJob;
use App\Models\AnalysisPage;
use App\Models\MetricResult;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnalyzeHtmlSeoJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);
        Storage::fake('analysis');
    }

    public function test_records_all_metrics_as_unavailable_when_no_html_was_fetched(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        (new AnalyzeHtmlSeoJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::AnalyzeHtmlSeo)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        $titleResult = MetricResult::query()
            ->whereHas('metricDefinition', fn ($q) => $q->where('key', 'title_present'))
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->first();

        $this->assertSame(MetricResultStatus::Unavailable, $titleResult->status);
        $this->assertNull($titleResult->score);
    }

    public function test_parses_stored_html_and_records_real_metrics(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        $html = '<html><head><title>Example Site</title>'
            .'<meta name="description" content="A test page"><meta name="viewport" content="width=device-width">'
            .'</head><body><h1>Hello</h1><img src="a.png" alt="a"><p>'.str_repeat('word ', 400).'</p></body></html>';

        $rawHtmlPath = app(AnalysisStoragePaths::class)->rawHtmlPath($websiteAnalysis->analysis_id, $websiteAnalysis->id, 'homepage.html');
        Storage::disk('analysis')->put($rawHtmlPath, $html);

        AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com',
            'final_url' => 'https://example.com',
            'page_type' => PageType::Homepage,
            'http_status' => 200,
            'raw_html_path' => $rawHtmlPath,
            'fetched_at' => now(),
        ]);

        (new AnalyzeHtmlSeoJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::AnalyzeHtmlSeo)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        $titleResult = MetricResult::query()
            ->whereHas('metricDefinition', fn ($q) => $q->where('key', 'title_present'))
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->first();

        $this->assertSame(MetricResultStatus::Success, $titleResult->status);
        $this->assertEquals($titleResult->max_score, $titleResult->score);

        $page = AnalysisPage::query()->where('website_analysis_id', $websiteAnalysis->id)->where('page_type', PageType::Homepage)->first();
        $this->assertSame('Example Site', $page->title);
    }
}
