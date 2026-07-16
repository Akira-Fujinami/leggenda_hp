<?php

namespace Tests\Unit;

use App\Exceptions\InvalidUrlException;
use App\Services\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UrlNormalizerTest extends TestCase
{
    private UrlNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new UrlNormalizer;
    }

    #[DataProvider('validUrlProvider')]
    public function test_it_normalizes_valid_urls(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize($input));
    }

    public static function validUrlProvider(): array
    {
        return [
            'bare domain gets https scheme' => ['example.com', 'https://example.com'],
            'already has scheme' => ['https://example.com', 'https://example.com'],
            'uppercase host is lowercased' => ['https://EXAMPLE.com', 'https://example.com'],
            'trailing slash removed' => ['https://example.com/', 'https://example.com'],
            'nested path trailing slash removed' => ['https://example.com/foo/', 'https://example.com/foo'],
            'fragment is dropped' => ['https://example.com/foo#section', 'https://example.com/foo'],
            'default https port removed' => ['https://example.com:443/foo', 'https://example.com/foo'],
            'default http port removed' => ['http://example.com:80/foo', 'http://example.com/foo'],
            'non-default port kept' => ['https://example.com:8443/foo', 'https://example.com:8443/foo'],
            'query string kept' => ['https://example.com/search?q=1', 'https://example.com/search?q=1'],
            'surrounding whitespace trimmed' => ['  example.com  ', 'https://example.com'],
        ];
    }

    #[DataProvider('invalidUrlProvider')]
    public function test_it_rejects_invalid_or_dangerous_urls(string $input): void
    {
        $this->expectException(InvalidUrlException::class);
        $this->normalizer->normalize($input);
    }

    public static function invalidUrlProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
            'ftp scheme rejected' => ['ftp://example.com'],
            'file scheme rejected' => ['file:///etc/passwd'],
            'javascript scheme rejected' => ['javascript:alert(1)'],
            'userinfo rejected' => ['https://user:pass@example.com'],
            'localhost rejected' => ['http://localhost'],
            'loopback ip rejected' => ['http://127.0.0.1'],
            'ipv6 loopback rejected' => ['http://[::1]'],
            'docker backend service rejected' => ['http://backend'],
            'docker postgres service rejected' => ['http://postgres:5432'],
            'docker redis service rejected' => ['http://redis'],
            'docker analyzer service rejected' => ['http://analyzer:3001'],
            'docker mailpit service rejected' => ['http://mailpit'],
            'docker host internal rejected' => ['http://host.docker.internal'],
            'docker gateway internal rejected' => ['http://gateway.docker.internal'],
            'malformed url rejected' => ['http://'],
        ];
    }
}
