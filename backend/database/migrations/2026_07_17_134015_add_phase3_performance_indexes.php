<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3で新設した比較・履歴比較APIのクエリパターン
 * (「同一プロジェクトの直近完了Analysisを探す」「metric_definition_idだけで
 * MetricResultを集計する」等)を高速化するための追加Index。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_results', function (Blueprint $table) {
            $table->index('metric_definition_id');
        });

        Schema::table('analyses', function (Blueprint $table) {
            $table->index(['project_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('metric_results', function (Blueprint $table) {
            $table->dropIndex(['metric_definition_id']);
        });

        Schema::table('analyses', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'completed_at']);
        });
    }
};
