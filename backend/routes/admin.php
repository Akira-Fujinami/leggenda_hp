<?php

use App\Http\Controllers\Admin\AnalysisController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\ComparisonController;
use App\Http\Controllers\Admin\DashboardController;
use Illuminate\Support\Facades\Route;

/**
 * 管理者ダッシュボード(社内の営業・診断管理画面)。DBのユーザー/roleには
 * 依存せず、共有アカウント(.envのADMIN_USERNAME/ADMIN_PASSWORD_HASH)+
 * Laravel sessionのadmin_authenticatedフラグのみで保護する(依頼者指定)。
 * 専用のログインページ(/admin/login)は持たない ―― 未認証時は
 * EnsureAdminAuthenticatedミドルウェアが/adminと同じURLのまま認証モーダル
 * のみのビューを返す。
 */
Route::prefix('admin')->name('admin.')->group(function () {
    // 未認証でも叩ける唯一のエンドポイント。認証モーダルのfetch()から呼ぶ。
    Route::post('/auth', [AuthController::class, 'authenticate'])
        ->middleware('throttle:admin-login')
        ->name('auth');

    Route::middleware('admin.auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.alias');

        Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
        Route::patch('/companies/{company}/sales-status', [CompanyController::class, 'updateSalesStatus'])->name('companies.sales-status');
        Route::patch('/companies/{company}/sales-note', [CompanyController::class, 'updateSalesNote'])->name('companies.sales-note');
        Route::patch('/lead-sessions/{leadSession}/reset-analyses-used', [CompanyController::class, 'resetAnalysesUsed'])->name('lead-sessions.reset-analyses-used');

        Route::get('/analyses', [AnalysisController::class, 'index'])->name('analyses.index');
        Route::get('/analyses/{analysis}', [AnalysisController::class, 'show'])->name('analyses.show');
        Route::patch('/analyses/{analysis}/force-terminate', [AnalysisController::class, 'forceTerminate'])->name('analyses.force-terminate');
        // 依頼AC(2026-08-27): 多社比較レポート(PDFのみ)のダウンロード。
        // admin.auth(共有アカウント)配下のため、リード向けdownloadReport()
        // のようなオーナーシップ検証は不要(既存のanalyses.show等と同じ、
        // 管理者は任意のAnalysisにアクセスできる前提)。
        Route::get('/analyses/{analysis}/comparison-report', [AnalysisController::class, 'downloadComparisonReport'])->name('analyses.comparison-report.download');

        // 依頼AB(2026-08-27): 無料診断を起点に、自社+競合3〜5社の比較を
        // 実行する。起点は既存の無料診断詳細画面のみ(独立した起票フォームは
        // 作らない、依頼者指定)ため、URLも/admin/analyses/{analysis}/compare
        // という「この診断から派生する」形にする。
        Route::get('/analyses/{analysis}/compare', [ComparisonController::class, 'create'])->name('analyses.compare.create');
        Route::post('/analyses/{analysis}/compare', [ComparisonController::class, 'store'])->name('analyses.compare.store');
    });
});
