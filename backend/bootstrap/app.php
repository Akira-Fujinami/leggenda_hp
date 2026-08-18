<?php

use App\Exceptions\Analysis\AnalysisAlreadyRunningException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAdminAuthenticated;
use App\Http\Middleware\ResolveLeadToken;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // 障害調査時にBackendログとFrontendのエラー表示を突き合わせられるよう、
        // 全APIリクエストにリクエストID(UUID)を付与する(X-Request-Idレスポンスヘッダー
        // として返す。frontend側で読めるようconfig/cors.phpのexposed_headersにも追加済み)。
        $middleware->api(prepend: [AssignRequestId::class]);
        $middleware->alias([
            'lead.token' => ResolveLeadToken::class,
            'admin.auth' => EnsureAdminAuthenticated::class,
        ]);
        // 2026-07-27に「$request->ip()が常にRenderのロードバランサーの
        // IPを返す」問題への対処として一度 trustProxies(at: '*') を
        // 追加したが、2026-07-28に前提が崩れていると判明したため撤回する:
        // BackendはRenderのWeb Serviceとして公開されており、frontend側の
        // BFF(backend-proxy.ts)を経由しないリクエストも直接Backendへ
        // 届き得る。「すべてのプロキシを信頼する」設定は、BFFを経由した
        // 正規のリクエストと、X-Forwarded-Forを自称するだけの偽装
        // リクエストを区別できない ―― 実IPが取れないままなりすましだけを
        // 許す、最悪の組み合わせになる。frontend側もこのヘッダーを
        // 転送していない(backend-proxy.tsのEXCLUDED_REQUEST_HEADERS参照)
        // ため、trustProxiesを設定しても実際には実IPは得られず、
        // リスクだけが残っていた。
        // 実IPの正しい伝播(BFFが検証済みの値を署名付きヘッダーで渡す等)は
        // 別途整理するまでの間、trustProxiesは未設定のままにし、
        // IPベースのレート制限が効かない前提でリードトークン単位の
        // 制限(RateLimiter::for('lead-consultation')等)を優先する。
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => '入力内容に誤りがあります。',
                'errors' => $e->errors(),
                'error_code' => 'VALIDATION_ERROR',
            ], 422);
        });

        $exceptions->render(function (AnalysisAlreadyRunningException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [],
                'error_code' => 'ANALYSIS_ALREADY_RUNNING',
            ], 409);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'ログインが必要です。',
                'errors' => [],
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        });

        // Illuminate\Auth\Access\AuthorizationException はLaravelの
        // prepareException()内でrender callbackが呼ばれる前に
        // AccessDeniedHttpException (= HttpExceptionInterface) へ変換されて
        // しまうため、専用のrender()コールバックを登録しても発火しない。
        // そのため403は以下の汎用ハンドラ内で明示的に扱う。
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*') || $e->getStatusCode() < 400) {
                return null;
            }

            if ($e->getStatusCode() === 403) {
                return response()->json([
                    'message' => 'この操作を実行する権限がありません。',
                    'errors' => [],
                    'error_code' => 'FORBIDDEN',
                ], 403);
            }

            return response()->json([
                'message' => $e->getMessage() ?: 'エラーが発生しました。',
                'errors' => [],
                'error_code' => 'HTTP_'.$e->getStatusCode(),
            ], $e->getStatusCode());
        });
    })->create();
