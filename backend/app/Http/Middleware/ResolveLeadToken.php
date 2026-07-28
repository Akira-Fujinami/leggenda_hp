<?php

namespace App\Http\Middleware;

use App\Services\Lead\LeadSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * リード向け公開エンドポイントの認可を担う。既存のauth:sanctum(Sanctumの
 * SPAセッションCookie認証)とは完全に独立した別系統であり、PolicyやGateも
 * 一切使わない ―― ここで検証したLeadSessionと、URL上のリソースの
 * lead_session_idが一致するかは各Controller側で個別に確認する。
 *
 * トークンは ?token= クエリパラメータ(初回アクセス時)、またはCookie
 * (2回目以降)のいずれかから読む。有効なトークンであれば、以後の
 * リクエストでも維持できるようCookieへ書き戻す(URLにトークンが
 * 残り続けてブラウザ履歴・リファラー経由で漏洩することを避けるため)。
 */
class ResolveLeadToken
{
    public const COOKIE_NAME = 'lead_token';

    public function __construct(private readonly LeadSessionService $leadSessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->query('token') ?? $request->cookie(self::COOKIE_NAME);

        // メッセージは理由(未指定/該当なし/期限切れ)によらず同一の文言に
        // 統一する ―― トークンの存在有無を画面から推測できてしまうことを
        // 避けるため。ログ側でのみ理由を区別する(2026-07-29対応)。
        if (! is_string($token) || $token === '') {
            Log::info('Lead token validation failed: missing');

            return response()->json([
                'message' => 'この診断URLは利用できません。お手数ですが、もう一度お申し込みください。',
                'errors' => [],
                'error_code' => 'LEAD_TOKEN_MISSING',
            ], 401);
        }

        $session = $this->leadSessions->findValidByToken($token);

        if ($session === null) {
            return response()->json([
                'message' => 'この診断URLは利用できません。お手数ですが、もう一度お申し込みください。',
                'errors' => [],
                'error_code' => 'LEAD_TOKEN_INVALID',
            ], 401);
        }

        $request->attributes->set('leadSession', $session);
        // 診断開始通知等が「診断結果を開く権限つきリンク」を組み立てる際に
        // 生トークンが必要なため、ここで一度検証済みの値をControllerへ渡せる
        // ようにしておく(token_hashからは逆算できないため、これが唯一の入手経路)。
        $request->attributes->set('leadToken', $token);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->setCookie(cookie(
            name: self::COOKIE_NAME,
            value: $token,
            minutes: (int) config('lead.token_expiry_days') * 24 * 60,
            path: '/',
            secure: app()->isProduction(),
            httpOnly: true,
            sameSite: 'lax',
        ));

        return $response;
    }
}
