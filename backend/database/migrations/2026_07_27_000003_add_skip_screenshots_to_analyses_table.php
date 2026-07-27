<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * リード向け簡易分析ではスクリーンショット撮影(CaptureScreenshotDesktop/
 * CaptureScreenshotMobile)を省略する。77指標のうちスクリーンショット由来の
 * 指標は0件のため、採点への影響はない。既定はfalse(既存の内部向けフル機能は
 * 常にこの値のまま、AnalysisPipelineの挙動は一切変わらない)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->boolean('skip_screenshots')->default(false)->after('skip_lighthouse');
        });
    }

    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->dropColumn('skip_screenshots');
        });
    }
};
