<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 管理者ダッシュボード(/admin)全体を保護する。DBのuser.role等には一切
 * 依存せず、Laravel session上のフラグ(admin_authenticated)だけを見る
 * (依頼者指定 ―― 共有アカウント方式、Admin\AuthController::authenticate()
 * のみがこのフラグをtrueにできる)。
 *
 * 本当のアクセス制御はここ(サーバーサイド)であり、画面側の認証モーダルは
 * UXのためだけの表層 ―― DevToolsでモーダルのDOMを消しても、セッションに
 * このフラグが無い限りここで必ず止まる(依頼者指定の「二重構造」)。
 *
 * GET(通常のページ遷移)で未認証の場合は、401を返す代わりに認証モーダルの
 * みを表示する専用ビュー(admin.guest)を返す ―― ダッシュボードのデータは
 * 一切renderに渡さない(依頼者指定「背景にダッシュボードを表示した状態で
 * モーダルだけ被せる設計は避ける」)。GET以外(フォーム送信・将来のJSON API)
 * はUIの出しようがないため401で止める。
 */
class EnsureAdminAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('admin_authenticated') === true) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return response()->view('admin.guest', [], 200);
        }

        abort(401, '管理者認証が必要です。');
    }
}
