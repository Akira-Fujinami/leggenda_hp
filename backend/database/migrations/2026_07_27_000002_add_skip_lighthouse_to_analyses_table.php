<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * リード向け簡易分析ではAnalyzerの単一Workerを長時間占有するLighthouseを
 * 省略する。既定はfalse(既存の内部向けフル機能は常にこの値のまま、
 * AnalysisPipelineの挙動は一切変わらない)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->boolean('skip_lighthouse')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->dropColumn('skip_lighthouse');
        });
    }
};
