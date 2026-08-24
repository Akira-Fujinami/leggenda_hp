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
    // Analyzer自身がquality低下→captured height半減→viewportフォールバックの
    // 束縛された再試行を全て試みても、安全なメモリ予算(ANALYZER_SCREENSHOT_MAX_BYTES等)
    // 内で撮影できなかった場合の専用コード。SCREENSHOT_FAILEDへ丸めず保持する。
    case ScreenshotResourceLimit = 'SCREENSHOT_RESOURCE_LIMIT';
    case LighthouseFailed = 'LIGHTHOUSE_FAILED';
    case TechnologyDetectionFailed = 'TECHNOLOGY_DETECTION_FAILED';
    case ParseFailed = 'PARSE_FAILED';
    case StorageWriteFailed = 'STORAGE_WRITE_FAILED';
    case DependencyUnavailable = 'DEPENDENCY_UNAVAILABLE';
    case JobTimeout = 'JOB_TIMEOUT';
    case MaxAttemptsExceeded = 'MAX_ATTEMPTS_EXCEEDED';
    case UnknownError = 'UNKNOWN_ERROR';
    // 2026-08-24追加: Job::failed()がQueryExceptionをSQLSTATEで分類するために
    // 追加(8/16〜17の本番障害で、positive_impressionカラム欠落によるQuery
    // ExceptionがJOB_TIMEOUTとして記録され調査をミスリードした再発防止)。
    // SchemaMismatch: undefined_column/undefined_table/datatype_mismatch
    // (マイグレーション未適用等、リトライしても直らない定義不一致)。
    case SchemaMismatch = 'SCHEMA_MISMATCH';
    // DatabaseError: それ以外のQueryException(デッドロック・接続断等、
    // 一過性の可能性がある)。
    case DatabaseError = 'DATABASE_ERROR';

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
            // マイグレーション未適用等の定義不一致はリトライしても解決せず、
            // 無駄に再試行してfailed_jobsを汚すだけ(2026-08-24追加、8月の
            // 障害ではattempts:2で同じ失敗が8件記録された)。
            self::SchemaMismatch,
        ], true);
    }
}
