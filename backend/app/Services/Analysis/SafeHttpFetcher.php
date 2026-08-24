<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Exceptions\Analysis\AnalysisException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * URL登録時のチェック(UrlNormalizer)とは別に、分析実行の直前・実アクセス時に
 * 改めてSSRF検証を行った上でHTTPを取得するサービス。
 * リダイレクトは自動追従させず、遷移先ごとに再検証してから手動で辿る。
 */
class SafeHttpFetcher
{
    public function __construct(private readonly SafeUrlValidator $validator) {}

    /**
     * @param  list<string>  $allowedContentTypePrefixes  空の場合はContent-Typeを検証しない
     * @param  ?int  $totalTimeoutSeconds  未指定時はconfig('analysis.http.total_timeout_seconds')。
     *   本番の実取得(FetchStaticPageJob等)とは別に、同期処理から短い締切りで
     *   1回だけ試したい呼び出し元(LeadAnalysisController::isSelfUrlUnreachable()
     *   等)向けの上書き。接続タイムアウトはこの値を超えないよう自動的に
     *   丸められる(下記min()参照)ため、別途指定する必要はない。
     */
    public function fetch(string $url, array $allowedContentTypePrefixes = [], ?int $totalTimeoutSeconds = null): FetchResult
    {
        $maxRedirects = (int) config('analysis.http.max_redirects');
        $maxBytes = (int) config('analysis.http.max_response_bytes');
        $connectTimeout = (int) config('analysis.http.connect_timeout_seconds');
        $totalTimeout = $totalTimeoutSeconds ?? (int) config('analysis.http.total_timeout_seconds');
        $userAgent = (string) config('analysis.crawler_user_agent');

        $requestedUrl = $url;
        $currentUrl = $url;
        $started = microtime(true);
        // リダイレクト追従全体(最大 $maxRedirects+1 回のHTTPリクエスト)を通して
        // 単一の締切りを守る。各ホップごとにtimeout()をリセットすると、
        // (max_redirects+1) * total_timeout_secondsまで累積し得て、
        // 呼び出し元Jobの$timeoutを超過してWorkerプロセスごと強制終了
        // させかねない(2026-07-25の本番障害調査で判明した設計不備)。
        $deadline = $started + $totalTimeout;

        for ($redirectCount = 0; $redirectCount <= $maxRedirects; $redirectCount++) {
            $this->validator->assertSafe($currentUrl);

            $remainingSeconds = $deadline - microtime(true);
            if ($remainingSeconds <= 0) {
                throw new AnalysisException(AnalysisErrorCode::RequestTimeout, "リダイレクトの追跡中にタイムアウトしました: {$requestedUrl}");
            }
            $hopTimeout = (int) max(1, ceil($remainingSeconds));

            try {
                $response = Http::withUserAgent($userAgent)
                    ->connectTimeout(min($connectTimeout, $hopTimeout))
                    ->timeout($hopTimeout)
                    ->withOptions([
                        'allow_redirects' => false,
                    ])
                    ->get($currentUrl);
            } catch (ConnectionException $e) {
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
                redirectCount: $redirectCount,
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
    private function readBodyWithLimit(Response $response, int $maxBytes): string
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
