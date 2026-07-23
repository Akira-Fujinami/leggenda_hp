<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Render等のPaaS向けliveness endpoint。DB/Redis/analyzerには一切触れない
// 単純な生存確認のみを行う (readinessが必要な場合は既存の /api/health を使う)。
// webミドルウェアグループ(セッション/CSRF等)も外し、依存を完全に持たない。
Route::get('/health', fn () => response()->json(['status' => 'ok']))
    ->withoutMiddleware(['web']);
