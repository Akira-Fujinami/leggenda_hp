<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AiAnalysisProviderの出力を保存する。website_analysis_id単位で生成する
 * (AiAnalysisInputFactoryがWebsiteAnalysis単位で入力を組み立てる設計に合わせる)。
 * Raw Prompt全文は保存しない ―― input_hashで同一入力への重複API呼び出しを防ぐ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_analysis_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_analysis_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('model')->nullable();
            $table->string('status');
            $table->text('summary')->nullable();
            $table->jsonb('strengths')->nullable();
            $table->jsonb('weaknesses')->nullable();
            $table->jsonb('priority_actions')->nullable();
            $table->jsonb('competitor_insights')->nullable();
            $table->jsonb('cautions')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->boolean('is_mock')->default(false);
            // 同一入力(AiAnalysisInputの内容)のハッシュ。再生成要求が来ても
            // 入力が変わっていなければAPIへ再送しない冪等性チェックに使う。
            $table->string('input_hash');
            $table->unsignedInteger('usage_input_tokens')->nullable();
            $table->unsignedInteger('usage_output_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['website_analysis_id', 'created_at']);
            $table->index(['analysis_id', 'created_at']);
            $table->index('input_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_analysis_results');
    }
};
