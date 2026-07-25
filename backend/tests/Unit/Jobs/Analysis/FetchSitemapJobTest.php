<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Jobs\Analysis\FetchSitemapJob;
use App\Models\MetricResult;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Database\Seeders\CategoryDefinitionSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FetchSitemapJobTest extends TestCase
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

    public function test_full_score_when_sitemap_exists_and_parses(): void
    {
        $xml = '<?xml version="1.0"?><urlset><url><loc>https://example.com/</loc></url></urlset>';
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response($xml, 200, ['Content-Type' => 'application/xml']),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new FetchSitemapJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::FetchSitemap)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        $result = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'sitemap_fetched'))->first();
        $this->assertSame(MetricResultStatus::Success, $result->status);
        $this->assertEquals(1, $result->raw_value['url_count']);
    }

    public function test_missing_sitemap_is_still_a_full_score_success(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('', 404),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new FetchSitemapJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $result = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'sitemap_fetched'))->first();
        $this->assertSame(MetricResultStatus::Success, $result->status);
        $this->assertEquals($result->max_score, $result->score);
    }

    public function test_403_response_is_marked_unavailable_not_a_false_exists(): void
    {
        // アクセス拒否(403)は「存在しない」ではなく「判定不能」であるべき。
        // normalized_value=trueの固定化バグの回帰テスト。
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('Forbidden', 403),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new FetchSitemapJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::FetchSitemap)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        $result = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'sitemap_fetched'))->first();
        $this->assertSame(MetricResultStatus::Unavailable, $result->status);
        $this->assertNull($result->normalized_value);
    }

    public function test_500_response_is_marked_unavailable_not_a_false_exists(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('Internal Server Error', 500),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new FetchSitemapJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $result = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'sitemap_fetched'))->first();
        $this->assertSame(MetricResultStatus::Unavailable, $result->status);
        $this->assertNull($result->normalized_value);
    }

    public function test_invalid_xml_is_still_treated_as_existing_with_parse_error_flag(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('not xml at all', 200, ['Content-Type' => 'text/plain']),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        (new FetchSitemapJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $result = MetricResult::query()->whereHas('metricDefinition', fn ($q) => $q->where('key', 'sitemap_fetched'))->first();
        $this->assertSame(MetricResultStatus::Success, $result->status);
        $this->assertTrue($result->normalized_value['value']);
        $this->assertTrue($result->raw_value['parse_error']);
    }
}
