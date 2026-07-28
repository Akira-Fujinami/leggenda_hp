<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email');

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        // リード向け公開エンドポイント。未認証のIPからのフォーム乱用・
        // 分析実行の乱発を防ぐ(1トークン1回の実行回数制限とは別に、
        // IP単位でも制限する)。
        RateLimiter::for('lead-onboarding', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });

        RateLimiter::for('lead-analysis-start', function (Request $request) {
            return Limit::perHour(5)->by($request->ip());
        });

        RateLimiter::for('lead-analysis-poll', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // 2026-07-28時点でtrustProxiesを設定していないため、IPベースの制限は
        // RenderのロードバランサーのIPに集約されて事実上機能しない
        // (全リードが同一IP扱いになる)。加えてbootstrap/app.php参照のとおり、
        // 仮にtrustProxiesを設定してもfrontend側がX-Forwarded-Forを転送
        // していないため実IPは得られない。したがって、この後に追加する
        // 新規の未認証エンドポイント(相談ボタン等)は、IPではなく
        // lead.tokenミドルウェアが解決済みのLeadSession単位で制限する
        // ―― こちらは偽装できない(トークン自体がハッシュ照合済みの
        // 認可情報のため)。lead.tokenはこのミドルウェアより必ず先に
        // 実行されるルート構成が前提。
        RateLimiter::for('lead-consultation', function (Request $request) {
            $leadSession = $request->attributes->get('leadSession');
            $key = $leadSession?->id ?? $request->ip();

            return Limit::perMinute(3)->by((string) $key);
        });
    }
}
