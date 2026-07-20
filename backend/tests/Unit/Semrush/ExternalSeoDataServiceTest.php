<?php

namespace Tests\Unit\Semrush;

use App\Models\Analysis;
use App\Models\ApiUsageLog;
use App\Models\ExternalDataSnapshot;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Semrush\ApiUsageLogger;
use App\Services\Semrush\ExternalSeoDataService;
use App\Services\Semrush\SeoDomainNormalizer;
use App\Services\Semrush\SeoProviderException;
use App\Services\Semrush\SeoProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalSeoDataServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsiteAnalysis(string $domain = 'example.com'): WebsiteAnalysis
    {
        $project = Project::factory()->create();
        $website = Website::factory()->for($project)->create(['normalized_url' => "https://{$domain}"]);
        $analysis = Analysis::factory()->for($project)->create();

        return WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
    }

    public function test_fetch_calls_the_provider_and_stores_a_snapshot(): void
    {
        config(['analysis.seo_provider' => 'mock']);

        $service = new ExternalSeoDataService(new SeoProviderFactory, new SeoDomainNormalizer, new ApiUsageLogger);
        $websiteAnalysis = $this->makeWebsiteAnalysis();

        $snapshot = $service->fetchFor($websiteAnalysis, $websiteAnalysis->analysis_id);

        $this->assertSame('success', $snapshot->status);
        $this->assertTrue($snapshot->is_mock);
        $this->assertSame('example.com', $snapshot->domain);
        $this->assertNotNull($snapshot->normalized_data);
        $this->assertDatabaseCount('api_usage_logs', 1);
    }

    public function test_second_fetch_for_the_same_domain_reuses_the_cached_snapshot_without_a_new_api_call(): void
    {
        config(['analysis.seo_provider' => 'mock']);

        $service = new ExternalSeoDataService(new SeoProviderFactory, new SeoDomainNormalizer, new ApiUsageLogger);
        $first = $this->makeWebsiteAnalysis('example.com');
        $second = $this->makeWebsiteAnalysis('example.com');

        $firstSnapshot = $service->fetchFor($first, $first->analysis_id);
        $secondSnapshot = $service->fetchFor($second, $second->analysis_id);

        $this->assertSame($firstSnapshot->id, $secondSnapshot->source_snapshot_id);
        // 2件目はキャッシュを再利用するため、ApiUsageLogは1件目の分しか増えない。
        $this->assertSame(1, ApiUsageLog::query()->count());
    }

    public function test_semrush_provider_without_api_key_throws_a_clear_configuration_error(): void
    {
        config(['analysis.seo_provider' => 'semrush', 'services.semrush.api_key' => '']);

        $service = new ExternalSeoDataService(new SeoProviderFactory, new SeoDomainNormalizer, new ApiUsageLogger);
        $websiteAnalysis = $this->makeWebsiteAnalysis();

        $this->expectException(SeoProviderException::class);
        $service->fetchFor($websiteAnalysis, $websiteAnalysis->analysis_id);
    }

    public function test_daily_limit_reached_prevents_further_calls(): void
    {
        config(['analysis.seo_provider' => 'mock', 'services.semrush.daily_unit_limit' => 5]);

        ApiUsageLog::factory()->create(['provider' => 'mock', 'units_used' => 10]);

        $service = new ExternalSeoDataService(new SeoProviderFactory, new SeoDomainNormalizer, new ApiUsageLogger);
        $websiteAnalysis = $this->makeWebsiteAnalysis();

        $this->expectException(SeoProviderException::class);
        $service->fetchFor($websiteAnalysis, $websiteAnalysis->analysis_id);
    }
}
