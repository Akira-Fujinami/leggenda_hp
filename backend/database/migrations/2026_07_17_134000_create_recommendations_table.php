<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_analysis_id')->constrained()->cascadeOnDelete();
            $table->foreignId('metric_result_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category_key');
            $table->string('title');
            $table->text('description');
            $table->jsonb('evidence')->nullable();
            $table->jsonb('current_value')->nullable();
            $table->jsonb('recommended_value')->nullable();
            $table->string('priority');
            $table->string('impact');
            $table->string('effort');
            $table->decimal('confidence', 3, 2)->default(1);
            $table->string('status')->default('open');
            $table->string('source')->default('rule');
            $table->decimal('sort_score', 8, 2)->default(0);
            $table->timestamps();

            // 同一Analysisの再実行(finalize再試行等)で重複した改善提案を
            // 作らないための冪等キー。1つのMetricResultからは基本1件の
            // 提案しか生成しない設計(metric_result_idがnullの行は
            // Postgresの標準動作によりNULL同士が衝突しないため複数許容される)。
            $table->unique(['website_analysis_id', 'metric_result_id']);
            $table->index(['website_analysis_id', 'priority']);
            $table->index('category_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
