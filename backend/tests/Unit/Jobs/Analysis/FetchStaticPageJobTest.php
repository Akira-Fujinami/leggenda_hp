<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Enums\PageType;
use App\Jobs\Analysis\AnalyzeHtmlSeoJob;
use App\Jobs\Analysis\FetchStaticPageJob;
use App\Models\AnalysisPage;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FetchStaticPageJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
    }

    private function makeWebsiteAnalysis(): WebsiteAnalysis
    {
        $website = Website::factory()->create(['url' => 'https://example.com', 'normalized_url' => 'https://example.com']);

        return WebsiteAnalysis::factory()->create(['website_id' => $website->id]);
    }

    public function test_successful_fetch_stores_html_and_updates_website_analysis(): void
    {
        Queue::fake([AnalyzeHtmlSeoJob::class]);
        Http::fake([
            'https://example.com/' => Http::response('<html><title>t</title></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();

        (new FetchStaticPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::FetchStaticPage)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        $page = AnalysisPage::query()->where('website_analysis_id', $websiteAnalysis->id)->where('page_type', PageType::Homepage)->first();
        $this->assertNotNull($page);
        $this->assertSame(200, $page->http_status);
        Storage::disk('analysis')->assertExists($page->raw_html_path);

        $websiteAnalysis->refresh();
        $this->assertSame(200, $websiteAnalysis->http_status);

        Queue::assertPushed(AnalyzeHtmlSeoJob::class, fn ($j) => $j->websiteAnalysisId === $websiteAnalysis->id);
    }

    public function test_failed_fetch_still_dispatches_analyze_html_seo_job(): void
    {
        Queue::fake([AnalyzeHtmlSeoJob::class]);

        $website = Website::factory()->create(['url' => 'https://localhost', 'normalized_url' => 'https://localhost']);
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['website_id' => $website->id]);

        (new FetchStaticPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::FetchStaticPage)->first();
        $this->assertSame(AnalysisJobStatus::Failed, $job->status);
        $this->assertSame(AnalysisErrorCode::UnsafeUrl->value, $job->error_code);

        Queue::assertPushed(AnalyzeHtmlSeoJob::class);
    }

    public function test_rerunning_a_completed_job_is_a_noop(): void
    {
        Queue::fake([AnalyzeHtmlSeoJob::class]);
        Http::fake([
            'https://example.com/' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $pipeline = app(AnalysisPipeline::class);

        (new FetchStaticPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle($pipeline);
        (new FetchStaticPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle($pipeline);

        Http::assertSentCount(1);
    }
}
