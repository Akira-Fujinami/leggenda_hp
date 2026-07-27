<?php

namespace App\Http\Middleware;

use App\Services\Lead\LeadSessionService;
use Closure;
use Illuminate\Http\Request;
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

        if (! is_string($token) || $token === '') {
            return response()->json([
                'message' => '診断用のリンクが無効です。もう一度フォームからお申し込みください。',
                'errors' => [],
                'error_code' => 'LEAD_TOKEN_MISSING',
            ], 401);
        }

        $session = $this->leadSessions->findValidByToken($token);

        if ($session === null) {
            return response()->json([
                'message' => '診断用のリンクの有効期限が切れています。もう一度フォームからお申し込みください。',
                'errors' => [],
                'error_code' => 'LEAD_TOKEN_INVALID',
            ], 401);
        }

        $request->attributes->set('leadSession', $session);

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
