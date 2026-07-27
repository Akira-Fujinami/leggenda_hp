<?php

use App\Http\Controllers\Api\AiAnalysisController;
use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Lead\LeadAnalysisController;
use App\Http\Controllers\Api\Lead\LeadOnboardingController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\WebsiteController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// リード向けセルフ診断(未ログインの公開エンドポイント)。auth:sanctumとは
// 完全に独立し、認可はlead.tokenミドルウェア(ワンタイムトークン検証)のみで行う。
Route::prefix('lead')->group(function () {
    Route::post('/onboarding', [LeadOnboardingController::class, 'store'])
        ->middleware('throttle:lead-onboarding');

    Route::middleware('lead.token')->group(function () {
        Route::post('/analyses', [LeadAnalysisController::class, 'store'])
            ->middleware('throttle:lead-analysis-start');
        Route::get('/analyses/{analysis}/progress', [LeadAnalysisController::class, 'progress'])
            ->middleware('throttle:lead-analysis-poll');
        Route::get('/analyses/{analysis}/results', [LeadAnalysisController::class, 'results'])
            ->middleware('throttle:lead-analysis-poll');
        Route::get('/website-analyses/{websiteAnalysis}/screenshots/{device}', [LeadAnalysisController::class, 'screenshot'])
            ->middleware('throttle:lead-analysis-poll')
            ->name('lead.analyses.screenshot');
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::patch('/projects/{project}', [ProjectController::class, 'update']);
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);

    Route::get('/projects/{project}/websites', [WebsiteController::class, 'index']);
    Route::post('/projects/{project}/websites', [WebsiteController::class, 'store']);
    Route::patch('/websites/{website}', [WebsiteController::class, 'update']);
    Route::delete('/websites/{website}', [WebsiteController::class, 'destroy']);

    Route::get('/projects/{project}/analyses', [AnalysisController::class, 'index']);
    Route::post('/projects/{project}/analyses', [AnalysisController::class, 'store']);
    Route::get('/analyses/{analysis}', [AnalysisController::class, 'show']);
    Route::get('/analyses/{analysis}/progress', [AnalysisController::class, 'progress']);
    Route::get('/analyses/{analysis}/results', [AnalysisController::class, 'results']);
    Route::get('/analyses/{analysis}/comparison', [AnalysisController::class, 'comparison']);
    Route::get('/analyses/{analysis}/history-comparison', [AnalysisController::class, 'historyComparison']);
    Route::get('/analyses/{analysis}/recommendations', [RecommendationController::class, 'forAnalysis']);
    Route::get('/website-analyses/{websiteAnalysis}/recommendations', [RecommendationController::class, 'forWebsiteAnalysis']);
    Route::get('/website-analyses/{websiteAnalysis}/screenshots/{device}', [AnalysisController::class, 'screenshot'])
        ->name('analyses.screenshot');

    Route::get('/website-analyses/{websiteAnalysis}/ai-analysis', [AiAnalysisController::class, 'show']);
    Route::post('/website-analyses/{websiteAnalysis}/ai-analysis', [AiAnalysisController::class, 'store'])
        ->middleware('throttle:10,1');
});
