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
use Database\Seeders\CategoryDefinitionSeeder;
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
        $this->seed(CategoryDefinitionSeeder::class);
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

    public function test_records_new_content_and_conversion_metrics_from_real_html(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        $html = '<html><head><title>Example Site</title></head><body>'
            .'<h1>Hello</h1>'
            .'<a href="/pricing">料金プラン</a>'
            .'<a href="/case-study">導入事例</a>'
            .'<a href="https://www.tablecheck.com/shops/example">ご予約はこちら</a>'
            .'<form><input type="email" name="contact_email" required><input type="submit" value="送信"></form>'
            .'</body></html>';

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

        $resultFor = fn (string $key) => MetricResult::query()
            ->whereHas('metricDefinition', fn ($q) => $q->where('key', $key))
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->first();

        $pricing = $resultFor('pricing_info_link_present');
        $this->assertSame(MetricResultStatus::Success, $pricing->status);
        $this->assertTrue($pricing->normalized_value['value']);

        $caseStudy = $resultFor('case_study_or_testimonial_link_present');
        $this->assertSame(MetricResultStatus::Success, $caseStudy->status);

        $faq = $resultFor('faq_link_present');
        $this->assertSame(MetricResultStatus::NotFound, $faq->status);
        $this->assertFalse($faq->normalized_value['value']);

        $reservationService = $resultFor('external_reservation_service_detected');
        $this->assertSame(MetricResultStatus::Success, $reservationService->status);
        $this->assertTrue($reservationService->normalized_value['value']);
        $this->assertSame('www.tablecheck.com', $reservationService->raw_value['host']);

        $burden = $resultFor('form_input_burden');
        $this->assertSame(MetricResultStatus::Success, $burden->status);
        $this->assertSame(1, $burden->normalized_value['value']);
        $this->assertSame('small', $burden->raw_value['tier']);
    }

    public function test_records_form_input_burden_as_not_found_when_there_is_no_form(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        $html = '<html><head><title>No Form</title></head><body><h1>Hello</h1></body></html>';
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

        $burden = MetricResult::query()
            ->whereHas('metricDefinition', fn ($q) => $q->where('key', 'form_input_burden'))
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->first();

        $this->assertSame(MetricResultStatus::NotFound, $burden->status);
    }
}
