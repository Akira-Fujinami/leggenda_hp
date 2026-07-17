<?php

namespace Tests\Unit\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Exceptions\Analysis\AnalysisException;
use App\Services\Analysis\SafeUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SafeUrlValidatorTest extends TestCase
{
    private SafeUrlValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SafeUrlValidator;
    }

    public function test_it_allows_a_public_https_url(): void
    {
        $result = $this->validator->assertSafe('https://8.8.8.8');

        $this->assertSame('8.8.8.8', $result['host']);
        $this->assertSame(['8.8.8.8'], $result['resolved_ips']);
    }

    #[DataProvider('unsafeUrlProvider')]
    public function test_it_rejects_unsafe_urls(string $url, AnalysisErrorCode $expectedCode): void
    {
        try {
            $this->validator->assertSafe($url);
            $this->fail("Expected AnalysisException for {$url}");
        } catch (AnalysisException $e) {
            $this->assertSame($expectedCode, $e->errorCode);
        }
    }

    public static function unsafeUrlProvider(): array
    {
        return [
            'ftp scheme' => ['ftp://8.8.8.8', AnalysisErrorCode::UnsafeUrl],
            'userinfo' => ['https://user:pass@8.8.8.8', AnalysisErrorCode::UnsafeUrl],
            'non-standard port' => ['https://8.8.8.8:9999', AnalysisErrorCode::UnsafeUrl],
            'blocked hostname backend' => ['http://backend', AnalysisErrorCode::UnsafeUrl],
            'blocked hostname localhost' => ['http://localhost', AnalysisErrorCode::UnsafeUrl],
            'loopback ip' => ['http://127.0.0.1', AnalysisErrorCode::PrivateIpBlocked],
            'private ip 10/8' => ['http://10.1.2.3', AnalysisErrorCode::PrivateIpBlocked],
            'private ip 172.16/12' => ['http://172.16.5.5', AnalysisErrorCode::PrivateIpBlocked],
            'private ip 192.168/16' => ['http://192.168.1.1', AnalysisErrorCode::PrivateIpBlocked],
            'link-local' => ['http://169.254.169.254', AnalysisErrorCode::PrivateIpBlocked],
            'ipv6 loopback' => ['http://[::1]', AnalysisErrorCode::PrivateIpBlocked],
            'ipv6 unique local' => ['http://[fc00::1]', AnalysisErrorCode::PrivateIpBlocked],
            'ipv4 multicast' => ['http://224.0.0.1', AnalysisErrorCode::PrivateIpBlocked],
            'ipv6 multicast' => ['http://[ff02::1]', AnalysisErrorCode::PrivateIpBlocked],
            'reserved unspecified' => ['http://0.0.0.0', AnalysisErrorCode::PrivateIpBlocked],
            'malformed url' => ['http://', AnalysisErrorCode::InvalidUrl],
        ];
    }

    public function test_it_fails_dns_resolution_for_nonexistent_domain(): void
    {
        $this->expectException(AnalysisException::class);

        try {
            $this->validator->assertSafe('https://this-domain-should-not-exist-abc123xyz.invalid');
        } catch (AnalysisException $e) {
            $this->assertSame(AnalysisErrorCode::DnsResolutionFailed, $e->errorCode);

            throw $e;
        }
    }
}
