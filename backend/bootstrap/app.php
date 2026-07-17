<?php

use App\Exceptions\Analysis\AnalysisAlreadyRunningException;
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
