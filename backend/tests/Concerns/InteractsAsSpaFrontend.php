<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * 実際のブラウザ(Next.js SPA)がSanctumとやり取りする手順
 * (Origin付きリクエスト → /sanctum/csrf-cookie → X-XSRF-TOKENを添えて送信)
 * をテストでも再現するためのヘルパー。Sanctumの EnsureFrontendRequestsAreStateful
 * は内部で独自にPipelineを組み立てて動くため、withoutMiddleware() ではCSRF検証を
 * 無効化できず、実際にトークンを取得して送る必要がある。
 */
trait InteractsAsSpaFrontend
{
    private const FRONTEND_ORIGIN = 'http://localhost:3000';

    private ?string $xsrfToken = null;

    private array $spaCookies = [];

    private function jsonAsFrontend(string $method, string $uri, array $data = []): TestResponse
    {
        if ($this->xsrfToken === null) {
            $this->primeCsrfCookie();
        }

        $headers = ['Origin' => self::FRONTEND_ORIGIN];

        if (! in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            $headers['X-XSRF-TOKEN'] = $this->xsrfToken;
        }

        $response = $this->withCookies($this->spaCookies)
            ->withHeaders($headers)
            ->json($method, $uri, $data);

        $this->captureCookies($response);

        return $response;
    }

    private function primeCsrfCookie(): void
    {
        $response = $this->withHeaders(['Origin' => self::FRONTEND_ORIGIN])
            ->get('/sanctum/csrf-cookie');

        $this->captureCookies($response);
    }

    private function captureCookies(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $this->spaCookies[$cookie->getName()] = $cookie->getValue();

            if ($cookie->getName() === 'XSRF-TOKEN' && $cookie->getValue() !== null) {
                $this->xsrfToken = urldecode($cookie->getValue());
            }
        }
    }
}
