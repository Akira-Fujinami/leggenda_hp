<?php

namespace Tests\Unit\Semrush;

use App\Services\Semrush\SemrushSeoDataProvider;
use App\Services\Semrush\SeoProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SemrushSeoDataProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.semrush.api_key' => 'test-key', 'services.semrush.max_retries' => 0]);
    }

    private function csv(array $headers, array $row): string
    {
        return implode(';', $headers)."\n".implode(';', $row);
    }

    public function test_parses_a_normal_response_including_the_real_authority_score_column(): void
    {
        Http::fake([
            '*type=domain_ranks*' => Http::response($this->csv(['Db', 'Dn', 'Rk', 'Or', 'Ot', 'Oc', 'Ad', 'At', 'Ac'], ['jp', 'example.com', '1000', '500', '12000', '300', '1', '10', '5']), 200),
            '*type=backlinks_overview*' => Http::response($this->csv(['total', 'domains_num', 'ascore'], ['4200', '150', '42']), 200),
            '*type=domain_domains*' => Http::response($this->csv(['Dn', 'Cr'], ['competitor.com', '0.5']), 200),
        ]);

        $result = (new SemrushSeoDataProvider)->fetch('example.com', 'jp');

        $this->assertFalse($result->isMock);
        // 捏造された近似値ではなく、backlinks_overviewの実列(ascore)から取得する。
        $this->assertSame(42.0, $result->domain->authorityScore);
        $this->assertSame(12000, $result->domain->organicTrafficEstimate);
        $this->assertSame(4200, $result->backlinks->backlinksCount);
        $this->assertSame(150, $result->backlinks->referringDomainsCount);
        $this->assertSame(1, $result->competitors->competitorDomainsCount);
        // 実キーで未検証のため、上位3/10位キーワード数は捏造せずnullのまま返す。
        $this->assertNull($result->keywords->top3KeywordsCount);
        $this->assertNull($result->keywords->top10KeywordsCount);
    }

    public function test_throws_a_non_retryable_auth_error_on_401(): void
    {
        Http::fake(['*api.semrush.com*' => Http::response('unauthorized', 401)]);

        try {
            (new SemrushSeoDataProvider)->fetch('example.com', 'jp');
            $this->fail('SeoProviderException was not thrown.');
        } catch (SeoProviderException $e) {
            $this->assertSame('SEMRUSH_AUTH_FAILED', $e->errorCode);
            $this->assertFalse($e->isRetryable);
        }
    }

    public function test_throws_a_retryable_rate_limit_error_honoring_retry_after(): void
    {
        Http::fake(['*api.semrush.com*' => Http::response('rate limited', 429, ['Retry-After' => '17'])]);

        try {
            (new SemrushSeoDataProvider)->fetch('example.com', 'jp');
            $this->fail('SeoProviderException was not thrown.');
        } catch (SeoProviderException $e) {
            $this->assertSame('SEMRUSH_RATE_LIMITED', $e->errorCode);
            $this->assertTrue($e->isRetryable);
            $this->assertSame(17, $e->retryAfterSeconds);
        }
    }

    public function test_throws_unavailable_after_connection_failures_are_exhausted(): void
    {
        Http::fake(function () {
            throw new ConnectionException('connection failed');
        });

        try {
            (new SemrushSeoDataProvider)->fetch('example.com', 'jp');
            $this->fail('SeoProviderException was not thrown.');
        } catch (SeoProviderException $e) {
            $this->assertSame('SEMRUSH_UNAVAILABLE', $e->errorCode);
            $this->assertTrue($e->isRetryable);
        }
    }

    public function test_throws_quota_exceeded_when_the_body_reports_insufficient_units(): void
    {
        Http::fake(['*api.semrush.com*' => Http::response('ERROR 50 :: NOT ENOUGH UNITS', 400)]);

        try {
            (new SemrushSeoDataProvider)->fetch('example.com', 'jp');
            $this->fail('SeoProviderException was not thrown.');
        } catch (SeoProviderException $e) {
            $this->assertSame('SEMRUSH_QUOTA_EXCEEDED', $e->errorCode);
            $this->assertFalse($e->isRetryable);
        }
    }
}
