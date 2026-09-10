<?php

use App\Exceptions\Analysis\AnalysisAlreadyRunningException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAdminAuthenticated;
use App\Http\Middleware\ResolveLeadToken;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
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
        // 2026-08-19: /admin/auth(fetch()から呼ぶJSON専用エンドポイント)で
        // CSRFトークン不一致(419)が発生した際、Accept: application/jsonを
        // 送っていたにも関わらずLaravelの標準HTML「Page Expired」ページ
        // (<!DOCTYPE html>...)が返り、フロントのresponse.json()が
        // "Unexpected token '<'"で失敗する不具合が実PDF...ではなく実curl
        // 確認で再現した(依頼者指摘)。原因はshouldRenderJsonWhen()が
        // `api/*`パスのみを対象にしており、/admin配下のJSON期待リクエスト
        // (Accept: application/json)が対象外だったため。
        // `admin/*`全体をJSON化すると、通常のページ遷移(GET /admin/companies/
        // 存在しないID 等)の404がJSONになり管理画面のHTMLエラーページが
        // 壊れるため、「adminパスかつAccept: application/jsonを明示的に
        // 要求している」場合のみJSON化する($request->expectsJson()で判定)。
        $wantsJson = fn (Request $request) => $request->is('api/*')
            || ($request->is('admin/*') && $request->expectsJson());

        $exceptions->shouldRenderJsonWhen($wantsJson);

        $exceptions->render(function (ValidationException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return response()->json([
                'message' => '入力内容に誤りがあります。',
                'errors' => $e->errors(),
                'error_code' => 'VALIDATION_ERROR',
            ], 422);
        });

        $exceptions->render(function (AnalysisAlreadyRunningException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [],
                'error_code' => 'ANALYSIS_ALREADY_RUNNING',
            ], 409);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'ログインが必要です。',
                'errors' => [],
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        });

        // TokenMismatchException(CSRF/419)はHttpExceptionInterfaceを実装して
        // いるため、専用のrender()を足さなくても以下の汎用ハンドラで拾われる
        // (getStatusCode()===419)。
        //
        // Illuminate\Auth\Access\AuthorizationException はLaravelの
        // prepareException()内でrender callbackが呼ばれる前に
        // AccessDeniedHttpException (= HttpExceptionInterface) へ変換されて
        // しまうため、専用のrender()コールバックを登録しても発火しない。
        // そのため403は以下の汎用ハンドラ内で明示的に扱う。
        // 依頼BR-1(2026-09-11): post_max_size(25M)を超えるPOSTは、PHP自身が
        // $_POST/$_FILES(CSRFトークン含む)を丸ごと破棄するため、通常の
        // セッション切れと見分けが付かないTokenMismatchException(419)に
        // なる(依頼BJ改-4の実HTTP検証で確認)。upload_max_filesizeを
        // 引き上げても(同依頼BR-1本体の対処)、post_max_size自体を超える
        // 経路はPHPがLaravelを起動する前に$_POSTを破棄するため、
        // アプリ側では原理的に防げない。ファイル添付を扱う2経路
        // (比較作成フォームのsales_deck・詳細画面の添付欄)に限り、
        // 419を英語の生エラーページのまま出さず日本語の案内へ差し替える
        // ―― $wantsJson()の判定より前に置き、JSON/非JSONどちらの
        // リクエストであってもこの2経路では常にこちらを優先する
        // (それ以外の419挙動は変えない、依頼者指定の対象範囲に絞る)。
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 419
                || ! $request->routeIs('admin.analyses.attachment.store', 'admin.analyses.compare.store')
            ) {
                return null;
            }

            $message = 'ファイルが大きすぎるか、セッションの有効期限が切れました。お手数ですが、ファイルを選び直してもう一度お試しください。';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => [],
                    'error_code' => 'UPLOAD_TOO_LARGE_OR_SESSION_EXPIRED',
                ], 419);
            }

            return back()->with('status', $message);
        });

        // 依頼BT-2(2026-09-11): 依頼BT-1でdisplay_errors=Offにした結果、
        // post_max_size超過時のPHP警告漏出は止まったが、代わりにLaravel
        // 組み込みのValidatePostSizeミドルウェアがPostTooLargeException
        // (413)を投げるようになった(依頼BSのrenderステージ実HTTP検証で
        // display_errors=Offを試した際に確認、依頼BT-1適用後もこの経路自体は
        // 変わらない)。413はPostTooLargeExceptionという専用の例外クラスで
        // 判定する(getStatusCode()===413ではなく型で絞る ―― 他の原因で
        // 偶然413を返すHttpExceptionが将来増えても誤って拾わないため)。
        // 413はサイズ超過が確定している場合のみ発生するため、419
        // (CSRFトークン失効 ―― セッション切れ等サイズ以外の原因もあり得る、
        // 上のハンドラ)とは文言を分ける。対象ルートは上の419ハンドラと
        // 同じ2経路に限る(依頼者指定、依頼BR-1の419ハンドラ自体は変更しない)。
        //
        // ValidatePostSizeはグローバルミドルウェア(ルーティング解決より前に
        // 実行される、Illuminate\Foundation\Configuration\Middleware::
        // getGlobalMiddleware()参照)のため、この時点では$request->route()が
        // 未確定で、419ハンドラで使っているrouteIs()は常にfalseを返して
        // しまう(実HTTP検証で発覚、依頼BR-1の419ハンドラは例外の発生
        // タイミングが異なる=ルーティング後のCSRFミドルウェアのため
        // routeIs()で問題なく動く)。パス自体はルーティング解決を経ずに
        // 判定できるis()を使う。
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if (! $request->is('admin/analyses/*/attachment', 'admin/analyses/*/compare')) {
                return null;
            }

            $message = 'ファイルが大きすぎます。お手数ですが、ファイルを選び直してもう一度お試しください。';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => [],
                    'error_code' => 'UPLOAD_TOO_LARGE',
                ], 413);
            }

            // 419ハンドラと違いback()->with('status', ...)は使えない ――
            // ValidatePostSizeがStartSession(webミドルウェアグループ、
            // ルーティング解決の一部として実行される)より前に発火するため、
            // この時点ではまだセッションが開始されておらず、フラッシュしても
            // Cookieに紐づくセッションには保存されない(実HTTP検証で、
            // フラッシュしたはずのメッセージが次のページに出ないことを確認)。
            // このアプリはlang/ja翻訳を持たず文言を直接組み立てる方針の
            // ため、ここも簡潔な自己完結のHTMLを直接返す。
            return response(
                '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
                .'<title>アップロードエラー</title></head><body>'
                .'<p>'.e($message).'</p>'
                .'<p><a href="javascript:history.back()">戻る</a></p>'
                .'</body></html>',
                413
            );
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request) || $e->getStatusCode() < 400) {
                return null;
            }

            if ($e->getStatusCode() === 403) {
                return response()->json([
                    'message' => 'この操作を実行する権限がありません。',
                    'errors' => [],
                    'error_code' => 'FORBIDDEN',
                ], 403);
            }

            if ($e->getStatusCode() === 419) {
                return response()->json([
                    'message' => 'セッションの有効期限が切れました。ページを再読み込みしてください。',
                    'errors' => [],
                    'error_code' => 'SESSION_EXPIRED',
                ], 419);
            }

            if ($e->getStatusCode() === 429) {
                return response()->json([
                    'message' => 'しばらく時間をおいてから再度お試しください。',
                    'errors' => [],
                    'error_code' => 'TOO_MANY_REQUESTS',
                ], 429);
            }

            return response()->json([
                'message' => $e->getMessage() ?: 'エラーが発生しました。',
                'errors' => [],
                'error_code' => 'HTTP_'.$e->getStatusCode(),
            ], $e->getStatusCode());
        });
    })->create();
