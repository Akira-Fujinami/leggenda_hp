<?php

use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\WebsiteController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

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
});
