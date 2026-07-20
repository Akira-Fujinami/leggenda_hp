<?php

namespace App\Services\Semrush;

use App\Models\ApiUsageLog;
use Illuminate\Support\Carbon;

/**
 * 外部API(Semrush等)の呼び出し実績を記録する。APIキーやレスポンス本文は
 * 一切保存しない ―― request_hashは呼び出しパラメータのハッシュのみ。
 */
class ApiUsageLogger
{
    public function requestHash(string $provider, string $operation, string $domain, string $database): string
    {
        return hash('sha256', "{$provider}:{$operation}:{$domain}:{$database}");
    }

    /**
     * Project/Analysis単位の上限は将来追加可能な設計としつつ、
     * MVPでは日次上限(SEMRUSH_DAILY_UNIT_LIMIT)のみ実装する。
     */
    public function hasReachedDailyLimit(string $provider): bool
    {
        $limit = config('services.semrush.daily_unit_limit');

        if ($limit === null) {
            return false;
        }

        $usedToday = (int) ApiUsageLog::query()
            ->where('provider', $provider)
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->sum('units_used');

        return $usedToday >= (int) $limit;
    }

    public function log(
        string $provider,
        string $operation,
        ?int $analysisId,
        ?int $websiteAnalysisId,
        string $requestHash,
        string $status,
        ?int $httpStatus = null,
        ?int $unitsUsed = null,
        ?int $durationMs = null,
        ?string $errorCode = null,
    ): ApiUsageLog {
        return ApiUsageLog::query()->create([
            'provider' => $provider,
            'operation' => $operation,
            'analysis_id' => $analysisId,
            'website_analysis_id' => $websiteAnalysisId,
            'request_hash' => $requestHash,
            'status' => $status,
            'http_status' => $httpStatus,
            'units_used' => $unitsUsed,
            'duration_ms' => $durationMs,
            'error_code' => $errorCode,
        ]);
    }
}
