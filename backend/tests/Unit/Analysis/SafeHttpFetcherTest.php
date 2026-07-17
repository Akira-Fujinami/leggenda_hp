<?php

namespace Tests\Unit\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Exceptions\Analysis\AnalysisException;
use App\Services\Analysis\SafeHttpFetcher;
use App\Services\Analysis\SafeUrlValidator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SafeHttpFetcherTest extends TestCase
{
    private SafeHttpFetcher $fetcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fetcher = new SafeHttpFetcher(new SafeUrlValidator);
    }

    public function test_it_fetches_a_successful_response(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('<html>ok</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->fetcher->fetch('https://example.com/');

        $this->assertSame(200, $result->httpStatus);
        $this->assertSame('<html>ok</html>', $result->body);
        $this->assertSame('https://example.com/', $result->finalUrl);
    }

    public function test_it_follows_and_revalidates_redirects(): void
    {
        Http::fake([
            'https://example.com/old' => Http::response('', 301, ['Location' => 'https://example.com/new']),
            'https://example.com/new' => Http::response('<html>moved</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->fetcher->fetch('https://example.com/old');

        $this->assertSame('https://example.com/new', $result->finalUrl);
        $this->assertSame('<html>moved</html>', $result->body);
    }

    public function test_it_rejects_a_redirect_to_an_unsafe_host(): void
    {
        Http::fake([
            'https://example.com/evil' => Http::response('', 302, ['Location' => 'http://backend:8000/']),
        ]);

        try {
            $this->fetcher->fetch('https://example.com/evil');
            $this->fail('Expected AnalysisException');
        } catch (AnalysisException $e) {
            $this->assertSame(AnalysisErrorCode::UnsafeUrl, $e->errorCode);
        }
    }

    public function test_it_gives_up_after_max_redirects(): void
    {
        Http::fake([
            'https://example.com/a' => Http::response('', 301, ['Location' => 'https://example.com/b']),
            'https://example.com/b' => Http::response('', 301, ['Location' => 'https://example.com/c']),
            'https://example.com/c' => Http::response('', 301, ['Location' => 'https://example.com/d']),
            'https://example.com/d' => Http::response('', 301, ['Location' => 'https://example.com/e']),
        ]);

        $this->expectException(AnalysisException::class);

        try {
            $this->fetcher->fetch('https://example.com/a');
        } catch (AnalysisException $e) {
            $this->assertSame(AnalysisErrorCode::TooManyRedirects, $e->errorCode);

            throw $e;
        }
    }

    public function test_it_rejects_a_response_whose_declared_content_length_exceeds_the_limit(): void
    {
        config(['analysis.http.max_response_bytes' => 10]);

        Http::fake([
            'https://example.com/' => Http::response(str_repeat('a', 1000), 200, [
                'Content-Type' => 'text/html',
                'Content-Length' => '1000',
            ]),
        ]);

        try {
            $this->fetcher->fetch('https://example.com/');
            $this->fail('Expected AnalysisException');
        } catch (AnalysisException $e) {
            $this->assertSame(AnalysisErrorCode::ResponseTooLarge, $e->errorCode);
        }
    }

    public function test_it_truncates_a_response_with_no_declared_length_that_exceeds_the_limit(): void
    {
        config(['analysis.http.max_response_bytes' => 10]);

        Http::fake([
            'https://example.com/' => Http::response(str_repeat('a', 1000), 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->fetcher->fetch('https://example.com/');

        $this->assertSame(10, strlen($result->body));
    }

    public function test_it_rejects_unsupported_content_types(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('binary', 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        try {
            $this->fetcher->fetch('https://example.com/', ['text/html']);
            $this->fail('Expected AnalysisException');
        } catch (AnalysisException $e) {
            $this->assertSame(AnalysisErrorCode::UnsupportedContentType, $e->errorCode);
        }
    }
}
