<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * 全APIリクエストにリクエストID(UUID)を付与する。
 *
 * - Log::withContext()により、このリクエスト中のログ出力すべてに自動的に含まれる
 *   (障害調査時にBackendログとFrontendのエラー表示を突き合わせられるようにするため)。
 * - レスポンスヘッダー X-Request-Id としても返す(frontend側で読めるよう
 *   config/cors.php の exposed_headers に追加済み)。
 * - なりすまし防止のため、クライアントから送られてきた値は信用せず常にサーバー側で生成する。
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);
        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
