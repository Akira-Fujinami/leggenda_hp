<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * 管理者ダッシュボードの認証(共有アカウント方式、依頼者指定)。DBに
 * ユーザーを作らず、.env(ADMIN_USERNAME/ADMIN_PASSWORD_HASH、
 * config('admin.*'))とのみ照合する。専用のログインページは持たない ――
 * 未認証時はEnsureAdminAuthenticatedミドルウェアがadmin.guestビュー
 * (認証モーダルのみのページ)を返し、そこからこのcontrollerへPOSTする。
 */
class AuthController extends Controller
{
    public function authenticate(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $expectedUsername = (string) config('admin.username');
        $expectedHash = (string) config('admin.password_hash');

        // ADMIN_USERNAME/ADMIN_PASSWORD_HASHが未設定の環境では、いかなる
        // 入力でもログインを成立させない(設定漏れが「誰でもログイン可能」に
        // ならないよう安全側に倒す)。
        $usernameMatches = $expectedUsername !== '' && hash_equals($expectedUsername, $credentials['username']);
        $passwordMatches = $expectedHash !== '' && Hash::check($credentials['password'], $expectedHash);

        if (! $usernameMatches || ! $passwordMatches) {
            // 「ユーザー名は合っているがパスワードが違う」等の詳細を返さない
            // (依頼者指定)。ユーザー名・パスワードそのものはログに出さない。
            return response()->json([
                'message' => 'ユーザー名またはパスワードが正しくありません。',
            ], 401);
        }

        // Session fixation対策 ―― 認証成功時にセッションIDを再発行する
        // (Api\AuthController::register/loginと同じ方針)。
        $request->session()->regenerate();
        $request->session()->put('admin_authenticated', true);

        return response()->json(['message' => 'ログインしました。']);
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('admin_authenticated');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/admin');
    }
}
