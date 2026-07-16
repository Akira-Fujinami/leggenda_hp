<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
        ]);

        Auth::login($user);

        // Origin/Refererが無く「stateful」と判定されないリクエストでは
        // セッションミドルウェアが起動していないため session() が例外を投げる。
        // 通常のブラウザからのリクエストでは常にOriginが送られるため通る想定だが、
        // 想定外のクライアントからのアクセスでも500にせず安全側に倒す。
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return $this->success(new UserResource($user), [], '登録しました。', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');
        $remember = (bool) $request->boolean('remember');

        if (! Auth::attempt($credentials, $remember)) {
            return response()->json([
                'message' => 'メールアドレスまたはパスワードが正しくありません。',
                'errors' => [],
                'error_code' => 'INVALID_CREDENTIALS',
            ], 401);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return $this->success(new UserResource(Auth::user()), [], 'ログインしました。');
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->success([], [], 'ログアウトしました。');
    }

    public function user(Request $request): JsonResponse
    {
        return $this->success(new UserResource($request->user()));
    }
}
