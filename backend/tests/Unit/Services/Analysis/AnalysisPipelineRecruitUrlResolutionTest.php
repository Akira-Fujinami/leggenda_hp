<?php

namespace Tests\Unit\Services\Analysis;

use App\Enums\MetricResultStatus;
use App\Enums\PageType;
use App\Jobs\Analysis\AnalyzeHtmlSeoJob;
use App\Jobs\Analysis\FetchRecruitPageJob;
use App\Models\AnalysisPage;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use Database\Seeders\CategoryDefinitionSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AnalyzeHtmlSeoJobがトップページのbusiness_links.recruitを検出した後、
 * その(相対または絶対の)hrefがFetchRecruitPageJobへ正しく絶対URLとして
 * 解決されて渡ることを確認する。
 */
class AnalysisPipelineRecruitUrlResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategoryDefinitionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);
        Storage::fake('analysis');
    }

    private function makeHomepageWithHtml(WebsiteAnalysis $websiteAnalysis, string $html, string $homepageUrl): void
    {
        $rawHtmlPath = app(AnalysisStoragePaths::class)->rawHtmlPath($websiteAnalysis->analysis_id, $websiteAnalysis->id, 'homepage.html');
        Storage::disk('analysis')->put($rawHtmlPath, $html);

        AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => $homepageUrl,
            'final_url' => $homepageUrl,
            'page_type' => PageType::Homepage,
            'http_status' => 200,
            'raw_html_path' => $rawHtmlPath,
            'fetched_at' => now(),
        ]);
    }

    public function test_a_relative_recruit_link_is_resolved_to_an_absolute_url_before_dispatch(): void
    {
        Queue::fake([FetchRecruitPageJob::class]);

        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        $html = '<html><head><title>Example</title></head><body><h1>Hello</h1>'
            .'<a href="/careers">採用情報はこちら</a>'
            .'</body></html>';
        $this->makeHomepageWithHtml($websiteAnalysis, $html, 'https://example.com');

        (new AnalyzeHtmlSeoJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $recruitLink = MetricResult::query()
            ->whereHas('metricDefinition', fn ($q) => $q->where('key', 'recruit_link_present'))
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->first();
        $this->assertSame(MetricResultStatus::Success, $recruitLink->status);

        // /careers(相対href)がトップページのURL(https://example.com)を基準に
        // 絶対URLへ解決された状態でFetchRecruitPageJobへ渡ること。
        Queue::assertPushed(FetchRecruitPageJob::class, fn ($job) => $job->recruitUrl === 'https://example.com/careers');
    }

    public function test_dispatch_recruit_page_fetch_resolves_the_relative_href_to_an_absolute_url(): void
    {
        Queue::fake([FetchRecruitPageJob::class]);

        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        $this->makeHomepageWithHtml($websiteAnalysis, '<html><body>x</body></html>', 'https://example.com/jp/');

        $definition = MetricDefinition::query()->where('key', 'recruit_link_present')->first();
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success,
            'normalized_value' => ['value' => true],
            'raw_value' => ['present' => true, 'url' => 'careers', 'text' => '採用情報', 'confidence' => 0.95, 'link_type' => 'internal'],
        ]);

        app(AnalysisPipeline::class)->dispatchRecruitPageFetch($websiteAnalysis->analysis_id, $websiteAnalysis->id);

        // ベースパス(/jp/)を基準にパス相対解決された絶対URLになること。
        Queue::assertPushed(FetchRecruitPageJob::class, fn ($job) => $job->recruitUrl === 'https://example.com/jp/careers');
    }

    public function test_no_recruit_link_detected_dispatches_fetch_job_with_a_null_href(): void
    {
        Queue::fake([FetchRecruitPageJob::class]);

        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        $html = '<html><head><title>Example</title></head><body><h1>Hello</h1></body></html>';
        $this->makeHomepageWithHtml($websiteAnalysis, $html, 'https://example.com');

        (new AnalyzeHtmlSeoJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        Queue::assertPushed(FetchRecruitPageJob::class, fn ($job) => $job->recruitUrl === null);
    }
}
