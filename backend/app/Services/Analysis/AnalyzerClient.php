<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Exceptions\Analysis\AnalysisException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * analyzer内部API (Docker内部ネットワークのみで到達可能) を呼び出すクライアント。
 * 共有シークレット(X-Analyzer-Token)による認証を必ず付与する。
 */
class AnalyzerClient
{
    /**
     * Lighthouseは低スペック/無料枠のanalyzerインスタンスで計測に数分かかることが
     * あるため、他のanalyzer呼び出しより大きく確保する。
     * RunLighthouseJob::$timeout(360秒)より30秒以上短く保つこと ―― そうしないと
     * このHTTP timeoutより先にJobのtimeoutが発火し、Workerプロセスごと
     * 強制終了されてしまう。
     */
    public const LIGHTHOUSE_TIMEOUT_SECONDS = 330;

    public const LIGHTHOUSE_CONNECT_TIMEOUT_SECONDS = 15;

    public function render(string $url, int $timeoutMs = 60000): array
    {
        return $this->post('/analyze/render', [
            'url' => $url,
            'timeout_ms' => $timeoutMs,
            'wait_until' => 'networkidle',
            'max_html_bytes' => config('analysis.http.max_response_bytes'),
        ], AnalysisErrorCode::RenderFailed, 90);
    }

    /**
     * スクリーンショットは画像データをJSONで返さず、Laravel/analyzer共有の
     * Dockerボリュームへanalyzer自身が保存する。analysis_id/website_analysis_idは
     * DBで存在確認済みの数値IDのみを渡すため、パストラバーサルの余地はない。
     */
    public function screenshot(int $analysisId, int $websiteAnalysisId, string $url, string $device, bool $fullPage = true): array
    {
        return $this->post('/analyze/screenshot', [
            'url' => $url,
            'device' => $device,
            'full_page' => $fullPage,
            'analysis_id' => $analysisId,
            'website_analysis_id' => $websiteAnalysisId,
        ], AnalysisErrorCode::ScreenshotFailed, 60);
    }

    public function lighthouse(string $url): array
    {
        return $this->post(
            '/analyze/lighthouse',
            ['url' => $url],
            AnalysisErrorCode::LighthouseFailed,
            self::LIGHTHOUSE_TIMEOUT_SECONDS,
            self::LIGHTHOUSE_CONNECT_TIMEOUT_SECONDS,
        );
    }

    public function technology(string $url, ?string $html = null): array
    {
        return $this->post('/analyze/technology', array_filter([
            'url' => $url,
            'html' => $html,
        ], fn ($v) => $v !== null), AnalysisErrorCode::TechnologyDetectionFailed, 60);
    }

    public function isHealthy(): bool
    {
        try {
            $response = Http::baseUrl($this->baseUrl())->timeout(3)->get('/health');

            return $response->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload, AnalysisErrorCode $defaultErrorCode, int $timeoutSeconds = 60, int $connectTimeoutSeconds = 5): array
    {
        $token = config('services.analyzer.token');

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->withHeaders($token ? ['X-Analyzer-Token' => $token] : [])
                ->timeout($timeoutSeconds)
                ->connectTimeout($connectTimeoutSeconds)
                ->post($path, $payload);
        } catch (ConnectionException $e) {
            throw new AnalysisException(AnalysisErrorCode::AnalyzerUnavailable, 'analyzerに接続できませんでした。', $e);
        }

        if ($response->status() === 401) {
            throw new AnalysisException(AnalysisErrorCode::AnalyzerAuthFailed, 'analyzerの認証に失敗しました。');
        }

        if ($response->status() === 429 || $response->status() === 503) {
            throw new AnalysisException(AnalysisErrorCode::AnalyzerUnavailable, 'analyzerが混雑しています。');
        }

        $body = $response->json();

        if (! $response->successful() || ! ($body['success'] ?? false)) {
            $message = $body['error']['message'] ?? 'analyzerでの処理に失敗しました。';
            $code = AnalysisErrorCode::tryFrom($body['error']['code'] ?? '') ?? $defaultErrorCode;

            throw new AnalysisException($code, $message);
        }

        return $body['data'] ?? [];
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.analyzer.url'), '/');
    }
}
