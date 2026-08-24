<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * 管理者ログイン
     *
     * DBのusersテーブルは使用せず、
     * ADMIN_USERNAME / ADMIN_PASSWORD_HASH と照合する。
     */
    public function authenticate(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $expectedUsername = (string) config('admin.username');
        $expectedPasswordHash = (string) config('admin.password_hash');

        /*
         * 環境変数が未設定の場合は必ず認証失敗。設定漏れによって認証を
         * 突破できないよう安全側に倒す。500(サーバー側の不備)で返す ――
         * 401(パスワード不一致)にすると、この状態が「監視・記録上は
         * 通常のログイン失敗と区別がつかない異常」として埋もれてしまう
         * (2026-08-24: この設定不備自体が5件のテスト失敗として放置されて
         * いた経緯があるため、意図的に区別する)。
         *
         * 応答本文には「認証が設定されていない」という内部状態を含めない
         * (未認証の相手に対してサーバーの設定状態を教える必要はないため)。
         * 具体的な理由はLog::errorでサーバー側にのみ記録する
         * (パスワード・ハッシュ値そのものは記録しない)。
         */
        if ($expectedUsername === '' || $expectedPasswordHash === '') {
            Log::error('Admin login attempted but admin credentials are not configured', [
                'username_configured' => $expectedUsername !== '',
                'password_hash_configured' => $expectedPasswordHash !== '',
            ]);

            return response()->json([
                'message' => 'ログインできませんでした。しばらくしてから再度お試しいただくか、管理者にお問い合わせください。',
            ], 500);
        }

        /*
         * ユーザー名はタイミング攻撃対策としてhash_equals()で比較する。
         * パスワードはADMIN_PASSWORD_HASH(Hash::make()の出力、bcrypt)と
         * Hash::check()で照合する ―― 万一.envが漏れても平文パスワードその
         * ものが残らないようにするため(config/admin.php参照)。
         */
        $usernameMatches = hash_equals(
            $expectedUsername,
            (string) $credentials['username']
        );

        $passwordMatches = Hash::check(
            (string) $credentials['password'],
            $expectedPasswordHash
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