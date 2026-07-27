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
    }
}
