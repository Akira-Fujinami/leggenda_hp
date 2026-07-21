<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Enums\PageType;
use App\Jobs\Analysis\ReanalyzeRenderedHtmlJob;
use App\Models\AnalysisPage;
use App\Models\MetricResult;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use Database\Seeders\CategoryDefinitionSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReanalyzeRenderedHtmlJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategoryDefinitionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);
        Storage::fake('analysis');
    }

    private function makeWebsiteAnalysis(): WebsiteAnalysis
    {
        $website = Website::factory()->create(['url' => 'https://example.com', 'normalized_url' => 'https://example.com']);

        return WebsiteAnalysis::factory()->create(['website_id' => $website->id]);
    }

    public function test_noop_when_rendered_html_path_is_missing(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis();
        AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com',
            'final_url' => 'https://example.com',
            'page_type' => PageType::Homepage,
            'http_status' => 200,
            'fetched_at' => now(),
        ]);

        (new ReanalyzeRenderedHtmlJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::ReanalyzeRenderedHtml)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);
        $this->assertSame(0, MetricResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }

    public function test_noop_when_rendered_html_file_is_missing_on_disk(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis();
        AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com',
            'final_url' => 'https://example.com',
            'page_type' => PageType::Homepage,
            'http_status' => 200,
            'rendered_html_path' => 'analyses/1/websites/1/pages/homepage.rendered.html',
            'fetched_at' => now(),
        ]);

        (new ReanalyzeRenderedHtmlJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::ReanalyzeRenderedHtml)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);
    }

    public function test_overwrites_static_results_with_rendered_source_preserving_the_same_row_id(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis();

        $staticHtml = '<html><head><title>Example</title></head><body><p>Loading...</p></body></html>';
        $renderedHtml = '<html><head><title>Example Rendered</title></head>'
            .'<body><h1>ホテル・旅館ランキング</h1><a href="https://www.instagram.com/example">Instagram</a>'
            .'<p>'.str_repeat('コンテンツ ', 20).'</p></body></html>';

        $paths = app(AnalysisStoragePaths::class);
        $rawHtmlPath = $paths->rawHtmlPath($websiteAnalysis->analysis_id, $websiteAnalysis->id, 'homepage.html');
        $renderedHtmlPath = $paths->rawHtmlPath($websiteAnalysis->analysis_id, $websiteAnalysis->id, 'homepage.rendered.html');
        Storage::disk('analysis')->put($rawHtmlPath, $staticHtml);
        Storage::disk('analysis')->put($renderedHtmlPath, $renderedHtml);

        $page = AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com',
            'final_url' => 'https://example.com',
            'page_type' => PageType::Homepage,
            'http_status' => 200,
            'raw_html_path' => $rawHtmlPath,
            'fetched_at' => now(),
        ]);

        // 一次解析(静的HTML)を先に実行して既存のstatic結果を作る。
        (new \App\Jobs\Analysis\AnalyzeHtmlSeoJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $h1Before = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'h1_single'))
            ->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertSame('static', $h1Before->source);
        $this->assertSame(0, $h1Before->raw_value['valid_count']); // 静的HTMLにはh1が無い
        $h1Id = $h1Before->id;

        $snsBefore = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'sns_link_present'))
            ->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertFalse($snsBefore->normalized_value['value']);

        // レンダリング済みHTMLが後から利用可能になったので二次解析を実行。
        $page->update(['rendered_html_path' => $renderedHtmlPath]);
        (new ReanalyzeRenderedHtmlJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::ReanalyzeRenderedHtml)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        $h1After = MetricResult::query()->where('id', $h1Id)->first();
        $this->assertSame($h1Id, $h1After->id, '同一のMetricResult行が更新される(新規行にならない)');
        $this->assertSame('rendered', $h1After->source);
        $this->assertSame(1, $h1After->raw_value['valid_count']);
        $this->assertTrue($h1After->evidence['changed_after_render'] ?? false);
        $this->assertFalse($h1After->evidence['previous_static_value'] ?? true);

        $snsAfter = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'sns_link_present'))
            ->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertTrue($snsAfter->normalized_value['value']);
        $this->assertSame('rendered', $snsAfter->source);
    }

    public function test_analyzer_exception_leaves_static_results_untouched(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis();

        $staticHtml = '<html><head><title>Example</title></head><body><h1>見出し</h1><p>'.str_repeat('本文コンテンツ ', 10).'</p></body></html>';
        $paths = app(AnalysisStoragePaths::class);
        $rawHtmlPath = $paths->rawHtmlPath($websiteAnalysis->analysis_id, $websiteAnalysis->id, 'homepage.html');
        $renderedHtmlPath = $paths->rawHtmlPath($websiteAnalysis->analysis_id, $websiteAnalysis->id, 'homepage.rendered.html');
        Storage::disk('analysis')->put($rawHtmlPath, $staticHtml);
        Storage::disk('analysis')->put($renderedHtmlPath, '<html></html>');

        $page = AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com',
            'final_url' => 'https://example.com',
            'page_type' => PageType::Homepage,
            'http_status' => 200,
            'raw_html_path' => $rawHtmlPath,
            'fetched_at' => now(),
        ]);

        (new \App\Jobs\Analysis\AnalyzeHtmlSeoJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $page->update(['rendered_html_path' => $renderedHtmlPath]);

        $this->mock(\App\Services\Analysis\HtmlSeoAnalyzer::class, function ($mock) {
            $mock->shouldReceive('analyze')->andThrow(new \RuntimeException('rendered HTML parse failed'));
        });

        (new ReanalyzeRenderedHtmlJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::ReanalyzeRenderedHtml)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        $h1 = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'h1_single'))
            ->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertSame('static', $h1->source);
        $this->assertSame(MetricResultStatus::Success, $h1->status);
    }
}
