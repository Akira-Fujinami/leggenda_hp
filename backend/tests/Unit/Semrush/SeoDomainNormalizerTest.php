<?php

namespace Tests\Unit\Semrush;

use App\Services\Semrush\SeoDomainNormalizer;
use App\Services\Semrush\SeoProviderException;
use Tests\TestCase;

class SeoDomainNormalizerTest extends TestCase
{
    private SeoDomainNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new SeoDomainNormalizer;
    }

    public function test_strips_www_prefix(): void
    {
        $result = $this->normalizer->normalize('https://www.example.com/path');

        $this->assertSame('example.com', $result->fullHost);
        $this->assertSame('example.com', $result->rootDomain);
        $this->assertFalse($result->isSubdomain);
    }

    public function test_identifies_a_subdomain_against_a_simple_tld(): void
    {
        $result = $this->normalizer->normalize('https://blog.example.com');

        $this->assertSame('blog.example.com', $result->fullHost);
        $this->assertSame('example.com', $result->rootDomain);
        $this->assertTrue($result->isSubdomain);
    }

    public function test_handles_compound_japanese_suffix(): void
    {
        $result = $this->normalizer->normalize('https://www.example.co.jp');

        $this->assertSame('example.co.jp', $result->rootDomain);
    }

    public function test_handles_subdomain_with_compound_suffix(): void
    {
        $result = $this->normalizer->normalize('https://shop.example.co.jp');

        $this->assertSame('example.co.jp', $result->rootDomain);
        $this->assertTrue($result->isSubdomain);
    }

    public function test_rejects_ip_addresses(): void
    {
        $this->expectException(SeoProviderException::class);
        $this->normalizer->normalize('http://203.0.113.5');
    }

    public function test_rejects_localhost(): void
    {
        $this->expectException(SeoProviderException::class);
        $this->normalizer->normalize('http://localhost:8000');
    }

    public function test_rejects_docker_service_hostnames(): void
    {
        $this->expectException(SeoProviderException::class);
        $this->normalizer->normalize('http://backend');
    }

    public function test_accepts_a_bare_hostname_without_scheme(): void
    {
        $result = $this->normalizer->normalize('example.com');

        $this->assertSame('example.com', $result->rootDomain);
    }
}
