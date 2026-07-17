<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Exceptions\Analysis\AnalysisException;
use Illuminate\Support\Facades\Http;

/**
 * URL登録時のチェック(UrlNormalizer)とは別に、分析実行の直前・実アクセス時に
 * 改めてSSRF検証を行った上でHTTPを取得するサービス。
 * リダイレクトは自動追従させず、遷移先ごとに再検証してから手動で辿る。
 */
class SafeHttpFetcher
{
    public function __construct(private readonly SafeUrlValidator $validator)
    {
    }

    /**
     * @param  list<string>  $allowedContentTypePrefixes  空の場合はContent-Typeを検証しない
     */
    public function fetch(string $url, array $allowedContentTypePrefixes = []): FetchResult
    {
        $maxRedirects = (int) config('analysis.http.max_redirects');
        $maxBytes = (int) config('analysis.http.max_response_bytes');
        $connectTimeout = (int) config('analysis.http.connect_timeout_seconds');
        $totalTimeout = (int) config('analysis.http.total_timeout_seconds');
        $userAgent = (string) config('analysis.crawler_user_agent');

        $requestedUrl = $url;
        $currentUrl = $url;
        $started = microtime(true);

        for ($redirectCount = 0; $redirectCount <= $maxRedirects; $redirectCount++) {
            $this->validator->assertSafe($currentUrl);

            try {
                $response = Http::withUserAgent($userAgent)
                    ->connectTimeout($connectTimeout)
                    ->timeout($totalTimeout)
                    ->withOptions([
                        'allow_redirects' => false,
                    ])
                    ->get($currentUrl);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                throw new AnalysisException(AnalysisErrorCode::ConnectionTimeout, "接続できませんでした: {$currentUrl}", $e);
            }

            $status = $response->status();

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');

                if (! $location) {
                    throw new AnalysisException(AnalysisErrorCode::HttpError, "リダイレクト先が指定されていません: {$currentUrl}");
                }

                $currentUrl = $this->resolveRedirectTarget($currentUrl, $location);

                if ($redirectCount === $maxRedirects) {
                    throw new AnalysisException(AnalysisErrorCode::TooManyRedirects, "リダイレクトが多すぎます: {$requestedUrl}");
                }

                continue;
            }

            $contentType = $response->header('Content-Type');

            if ($allowedContentTypePrefixes !== [] && $contentType !== null) {
                $matches = false;
                foreach ($allowedContentTypePrefixes as $prefix) {
                    if (str_starts_with(strtolower($contentType), $prefix)) {
                        $matches = true;
                        break;
                    }
                }

                if (! $matches) {
                    throw new AnalysisException(
                        AnalysisErrorCode::UnsupportedContentType,
                        "対応していないContent-Typeです: {$contentType}",
                    );
                }
            }

            $body = $this->readBodyWithLimit($response, $maxBytes);

            return new FetchResult(
                requestedUrl: $requestedUrl,
                finalUrl: $currentUrl,
                httpStatus: $status,
                body: $body,
                contentType: $contentType,
                durationMs: (int) round((microtime(true) - $started) * 1000),
            );
        }

        throw new AnalysisException(AnalysisErrorCode::TooManyRedirects, "リダイレクトが多すぎます: {$requestedUrl}");
    }

    /**
     * Content-Lengthヘッダーで事前に大きすぎるレスポンスを弾いた上で、
     * 万一ヘッダーが不正確でも実際のボディ長で確実に打ち切る。
     * (Guzzleの完全ストリーミング制御はHTTPテストのFake機構と相性が悪いため、
     * MVPではボディ取得後の切り詰めで代替する)
     */
    private function readBodyWithLimit(\Illuminate\Http\Client\Response $response, int $maxBytes): string
    {
        $declaredLength = $response->header('Content-Length');
        if ($declaredLength !== null && (int) $declaredLength > $maxBytes) {
            throw new AnalysisException(
                AnalysisErrorCode::ResponseTooLarge,
                "レスポンスサイズが上限を超えています: {$declaredLength} bytes",
            );
        }

        $body = $response->body();

        return strlen($body) > $maxBytes ? substr($body, 0, $maxBytes) : $body;
    }

    private function resolveRedirectTarget(string $currentUrl, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $base = parse_url($currentUrl);

        if ($base === false || ! isset($base['scheme'], $base['host'])) {
            throw new AnalysisException(AnalysisErrorCode::HttpError, "リダイレクト元URLが不正です: {$currentUrl}");
        }

        $port = isset($base['port']) ? ':'.$base['port'] : '';
        $origin = "{$base['scheme']}://{$base['host']}{$port}";

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $basePath = isset($base['path']) ? preg_replace('#/[^/]*$#', '/', $base['path']) : '/';

        return $origin.$basePath.$location;
    }
}
