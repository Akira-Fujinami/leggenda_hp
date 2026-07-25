<?php

namespace App\Enums;

enum AnalysisErrorCode: string
{
    case InvalidUrl = 'INVALID_URL';
    case UnsafeUrl = 'UNSAFE_URL';
    case DnsResolutionFailed = 'DNS_RESOLUTION_FAILED';
    case PrivateIpBlocked = 'PRIVATE_IP_BLOCKED';
    case ConnectionTimeout = 'CONNECTION_TIMEOUT';
    case RequestTimeout = 'REQUEST_TIMEOUT';
    case TooManyRedirects = 'TOO_MANY_REDIRECTS';
    case ResponseTooLarge = 'RESPONSE_TOO_LARGE';
    case UnsupportedContentType = 'UNSUPPORTED_CONTENT_TYPE';
    case HttpError = 'HTTP_ERROR';
    case RobotsBlocked = 'ROBOTS_BLOCKED';
    case AnalyzerUnavailable = 'ANALYZER_UNAVAILABLE';
    case AnalyzerAuthFailed = 'ANALYZER_AUTH_FAILED';
    // 502/504、または非JSON応答。analyzerアプリ自身が処理して返した
    // エラー(ANALYZER_UNAVAILABLE等)とは異なり、Renderのプロキシや
    // コンテナクラッシュ/再起動中などanalyzerアプリに到達すらしていない
    // 可能性が高い失敗を区別するための分類。
    case AnalyzerGatewayError = 'ANALYZER_GATEWAY_ERROR';
    case RenderFailed = 'RENDER_FAILED';
    case ScreenshotFailed = 'SCREENSHOT_FAILED';
    // page.screenshot()自体のタイムアウト。ナビゲーション完了後の後続処理で
    // 発生するため、NAVIGATION_TIMEOUTやSCREENSHOT_FAILEDに丸めず専用コード
    // として保持する(2026-07-25 ユニクロ調査で判明した誤分類の修正)。
    case ScreenshotTimeout = 'SCREENSHOT_TIMEOUT';
    case LighthouseFailed = 'LIGHTHOUSE_FAILED';
    case TechnologyDetectionFailed = 'TECHNOLOGY_DETECTION_FAILED';
    case ParseFailed = 'PARSE_FAILED';
    case StorageWriteFailed = 'STORAGE_WRITE_FAILED';
    case DependencyUnavailable = 'DEPENDENCY_UNAVAILABLE';
    case JobTimeout = 'JOB_TIMEOUT';
    case MaxAttemptsExceeded = 'MAX_ATTEMPTS_EXCEEDED';
    case UnknownError = 'UNKNOWN_ERROR';

    /**
     * このエラーはリトライしても解決しないため、Jobを即failed扱いにしてよいか。
     */
    public function isRetryable(): bool
    {
        return ! in_array($this, [
            self::InvalidUrl,
            self::UnsafeUrl,
            self::PrivateIpBlocked,
            self::UnsupportedContentType,
            self::RobotsBlocked,
            self::AnalyzerAuthFailed,
            // 依存元のデータ(HTML等)がそもそも存在しないため、retryしても
            // 結果は変わらない。
            self::DependencyUnavailable,
        ], true);
    }
}
