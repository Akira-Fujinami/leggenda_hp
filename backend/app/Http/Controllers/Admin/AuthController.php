<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    /**
     * 管理者ログイン
     *
     * DBのusersテーブルは使用せず、
     * ADMIN_USERNAME / ADMIN_PASSWORD と照合する。
     */
    public function authenticate(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $expectedUsername = (string) config('admin.username');
        $expectedPassword = (string) config('admin.password');

        /*
         * 環境変数が未設定の場合は必ず認証失敗。
         * 設定漏れによって認証を突破できないよう安全側に倒す。
         */
        if ($expectedUsername === '' || $expectedPassword === '') {
            return response()->json([
                'message' => '管理者認証が設定されていません。',
            ], 500);
        }

        /*
         * タイミング攻撃対策としてhash_equals()で比較する。
         *
         * ADMIN_PASSWORDには平文パスワードを設定する仕様なので、
         * Hash::check()は使用しない。
         */
        $usernameMatches = hash_equals(
            $expectedUsername,
            (string) $credentials['username']
        );

        $passwordMatches = hash_equals(
            $expectedPassword,
            (string) $credentials['password']
        );

        if (! $usernameMatches || ! $passwordMatches) {
            return response()->json([
                'message' => 'ユーザー名またはパスワードが正しくありません。',
            ], 401);
        }

        /*
         * Session Fixation対策。
         * ログイン成功時にセッションIDを再生成する。
         */
        $request->session()->regenerate();

        $request->session()->put('admin_authenticated', true);

        return response()->json([
            'message' => 'ログインしました。',
            'success' => true,
        ]);
    }

    /**
     * 管理者ログアウト
     */
    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('admin_authenticated');

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/admin');
    }
}