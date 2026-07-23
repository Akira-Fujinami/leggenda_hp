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
use Illuminate\Support\Facades\Http;
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

    public function test_switching_from_mock_to_semrush_for_the_same_domain_does_not_reuse_the_old_mock_snapshot(): void
    {
        // 1回目: mockでfetchし、is_mock=trueのスナップショットをキャッシュとして残す。
        config(['analysis.seo_provider' => 'mock']);
        $service = new ExternalSeoDataService(new SeoProviderFactory, new SeoDomainNormalizer, new ApiUsageLogger);
        $mockRun = $this->makeWebsiteAnalysis('example.com');
        $service->fetchFor($mockRun, $mockRun->analysis_id);

        // 2回目: 同じドメインだが、providerがsemrushに切り替わったケース。
        // providerでの絞り込みにより、古いmockスナップショットは再利用されず、
        // 新たに(fake経由で)実プロバイダへのリクエストが発生するはず。
        config(['analysis.seo_provider' => 'semrush', 'services.semrush.api_key' => 'test-key', 'services.semrush.max_retries' => 0]);
        Http::fake([
            '*type=domain_ranks*' => Http::response("Db;Dn;Rk;Or;Ot;Oc;Ad;At;Ac\nus;example.com;1000;500;12000;300;0;0;0", 200),
            '*type=backlinks_overview*' => Http::response("total;domains_num;ascore\n1500;120;42", 200),
            '*type=domain_domains*' => Http::response('', 200),
        ]);
        $semrushRun = $this->makeWebsiteAnalysis('example.com');
        $semrushSnapshot = $service->fetchFor($semrushRun, $semrushRun->analysis_id);

        $this->assertFalse($semrushSnapshot->is_mock);
        $this->assertSame('semrush', $semrushSnapshot->provider);
        $this->assertNull($semrushSnapshot->source_snapshot_id);
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'type=domain_ranks'));
    }

    public function test_a_snapshot_with_mismatched_is_mock_is_not_reused_as_cache(): void
    {
        config(['analysis.seo_provider' => 'mock']);

        // provider='mock'なのにis_mock=falseという、本来ありえない不整合な行を
        // 意図的に用意する(将来の実装ミスやデータ不整合に対する防御を確認する)。
        $inconsistent = ExternalDataSnapshot::factory()->create([
            'provider' => 'mock',
            'is_mock' => false,
            'domain' => 'example.com',
            'database' => 'us',
            'scope' => 'root_domain',
        ]);

        $service = new ExternalSeoDataService(new SeoProviderFactory, new SeoDomainNormalizer, new ApiUsageLogger);
        $websiteAnalysis = $this->makeWebsiteAnalysis('example.com');
        $snapshot = $service->fetchFor($websiteAnalysis, $websiteAnalysis->analysis_id);

        $this->assertNotSame($inconsistent->id, $snapshot->source_snapshot_id);
        $this->assertTrue($snapshot->is_mock);
    }

    public function test_a_snapshot_with_a_different_scope_is_not_reused_as_cache(): void
    {
        config(['analysis.seo_provider' => 'mock']);

        // 現状scope()は常に'root_domain'を返すが、将来サブドメインスコープが
        // 実装された際に異なるscopeのキャッシュを誤って再利用しないことを確認する。
        $subdomainSnapshot = ExternalDataSnapshot::factory()->create([
            'provider' => 'mock',
            'is_mock' => true,
            'domain' => 'example.com',
            'database' => 'us',
            'scope' => 'subdomain',
        ]);

        $service = new ExternalSeoDataService(new SeoProviderFactory, new SeoDomainNormalizer, new ApiUsageLogger);
        $websiteAnalysis = $this->makeWebsiteAnalysis('example.com');
        $snapshot = $service->fetchFor($websiteAnalysis, $websiteAnalysis->analysis_id);

        $this->assertNotSame($subdomainSnapshot->id, $snapshot->source_snapshot_id);
        $this->assertSame('root_domain', $snapshot->scope);
    }
}
