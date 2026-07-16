<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'analyzer' => $this->checkAnalyzer(),
        ];

        $isHealthy = ! in_array(false, $checks, strict: true);

        return response()->json([
            'data' => [
                'status' => $isHealthy ? 'ok' : 'degraded',
                'checks' => array_map(fn ($ok) => $ok ? 'ok' : 'error', $checks),
            ],
            'meta' => [],
            'message' => null,
        ], $isHealthy ? 200 : 503);
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable $e) {
            Log::warning('health_check.database_failed');

            return false;
        }
    }

    private function checkRedis(): bool
    {
        try {
            return Redis::connection()->ping() !== false;
        } catch (\Throwable $e) {
            Log::warning('health_check.redis_failed');

            return false;
        }
    }

    private function checkAnalyzer(): bool
    {
        try {
            $url = rtrim(config('services.analyzer.url'), '/').'/health';

            return Http::timeout(2)->get($url)->successful();
        } catch (\Throwable $e) {
            Log::warning('health_check.analyzer_failed');

            return false;
        }
    }
}
