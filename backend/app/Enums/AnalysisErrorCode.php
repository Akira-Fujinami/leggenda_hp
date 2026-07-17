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
    case RenderFailed = 'RENDER_FAILED';
    case ScreenshotFailed = 'SCREENSHOT_FAILED';
    case LighthouseFailed = 'LIGHTHOUSE_FAILED';
    case TechnologyDetectionFailed = 'TECHNOLOGY_DETECTION_FAILED';
    case ParseFailed = 'PARSE_FAILED';
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
        ], true);
    }
}
