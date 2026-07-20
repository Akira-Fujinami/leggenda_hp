<?php

namespace Tests\Unit\Semrush;

use App\Models\ApiUsageLog;
use App\Services\Semrush\ApiUsageLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiUsageLoggerTest extends TestCase
{
    use RefreshDatabase;

    private ApiUsageLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new ApiUsageLogger;
    }

    public function test_request_hash_is_deterministic_and_excludes_api_key(): void
    {
        $hash1 = $this->logger->requestHash('semrush', 'domain_overview', 'example.com', 'us');
        $hash2 = $this->logger->requestHash('semrush', 'domain_overview', 'example.com', 'us');

        $this->assertSame($hash1, $hash2);
        $this->assertStringNotContainsString('secret-key', $hash1);
    }

    public function test_request_hash_differs_for_different_domains(): void
    {
        $hash1 = $this->logger->requestHash('semrush', 'domain_overview', 'example.com', 'us');
        $hash2 = $this->logger->requestHash('semrush', 'domain_overview', 'other.com', 'us');

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_no_daily_limit_configured_means_never_reached(): void
    {
        config(['services.semrush.daily_unit_limit' => null]);

        $this->assertFalse($this->logger->hasReachedDailyLimit('semrush'));
    }

    public function test_daily_limit_is_reached_once_units_used_today_meet_the_limit(): void
    {
        config(['services.semrush.daily_unit_limit' => 20]);

        ApiUsageLog::factory()->create(['provider' => 'semrush', 'units_used' => 15]);
        $this->assertFalse($this->logger->hasReachedDailyLimit('semrush'));

        ApiUsageLog::factory()->create(['provider' => 'semrush', 'units_used' => 10]);
        $this->assertTrue($this->logger->hasReachedDailyLimit('semrush'));
    }

    public function test_log_creates_a_row_without_storing_the_api_key(): void
    {
        $log = $this->logger->log(
            provider: 'semrush',
            operation: 'domain_overview',
            analysisId: null,
            websiteAnalysisId: null,
            requestHash: 'abc123',
            status: 'success',
            httpStatus: 200,
            unitsUsed: 10,
            durationMs: 250,
        );

        $this->assertDatabaseHas('api_usage_logs', ['id' => $log->id, 'provider' => 'semrush', 'status' => 'success']);
    }
}
