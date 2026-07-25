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

    /**
     * 対応する呼び出し元Job(RenderPageJob/CaptureScreenshotJob/DetectTechnologyJob)の
     * $timeoutより30秒以上短く保つこと ―― 同値だとanalyzerが応答をハングさせた
     * 場合にこのHTTP timeoutとJobのtimeout(pcntl_alarm)が競合し、先にJob
     * timeoutが発火するとWorkerプロセスごと強制終了され得る
     * (RunLighthouseJobで実際に発生した障害と同じクラスの不具合)。
     */
    public const RENDER_TIMEOUT_SECONDS = 90;

    public const SCREENSHOT_TIMEOUT_SECONDS = 60;

    public const TECHNOLOGY_TIMEOUT_SECONDS = 60;

    public function render(string $url, int $timeoutMs = 60000): array
    {
        return $this->post('/analyze/render', [
            'url' => $url,
            'timeout_ms' => $timeoutMs,
            'max_html_bytes' => config('analysis.http.max_response_bytes'),
        ], AnalysisErrorCode::RenderFailed, self::RENDER_TIMEOUT_SECONDS);
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
        ], AnalysisErrorCode::ScreenshotFailed, self::SCREENSHOT_TIMEOUT_SECONDS);
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
        ], fn ($v) => $v !== null), AnalysisErrorCode::TechnologyDetectionFailed, self::TECHNOLOGY_TIMEOUT_SECONDS);
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

        $status = $response->status();

        if ($status === 401) {
            throw new AnalysisException(AnalysisErrorCode::AnalyzerAuthFailed, 'analyzerの認証に失敗しました。');
        }

        if ($status === 429 || $status === 503) {
            throw new AnalysisException(AnalysisErrorCode::AnalyzerUnavailable, 'analyzerが混雑しています。');
        }

        // 502/504はanalyzerアプリ自身が返すルートではない(server.tsの全ルートは
        // 401/422/503/500のいずれかで応答する)。Renderのプロキシやコンテナの
        // クラッシュ/再起動中に返される可能性が高く、analyzerが実際に処理して
        // 失敗した場合と区別できるよう専用のエラーコードにする。
        if ($status === 502 || $status === 504) {
            throw new AnalysisException(AnalysisErrorCode::AnalyzerGatewayError, "analyzerへの接続がゲートウェイで失敗しました(HTTP {$status})。");
        }

        $body = $response->json();

        // $response->json()はデコード失敗時に例外を投げずnullを返す。analyzerは
        // 常にJSONで応答する設計のため、非JSON本文(Renderのプロキシが返す
        // デフォルトのエラーページ等)もゲートウェイ由来の失敗として扱う。
        if ($body === null) {
            throw new AnalysisException(AnalysisErrorCode::AnalyzerGatewayError, 'analyzerから不正な応答(JSON以外)を受信しました。');
        }

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
