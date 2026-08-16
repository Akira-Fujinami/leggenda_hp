<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 改善提案(page6)のAI生成テキストを保持する新テーブル(2026-08-17)。
 *
 * 既存のBrandWheelAnalysisResultはWebsiteAnalysis単位(自社/競合それぞれ1件)
 * だが、改善提案は「自社×競合」の比較単位の成果物のため、Analysis単位
 * (analysis_idにunique制約、1診断につき1件)で別テーブルに保持する。
 *
 * 生成は自社(＋競合)のブランドホイールAI分析が両方とも終端状態(success/
 * insufficient_input/error)に達した時点でBrandWheelImprovementSuggestion
 * Dispatcherが1回だけdispatchする(GenerateBrandWheelImprovementSuggestionJob)。
 * 既存のBrandWheelAnalysisResultと同じく生の入出力全文は保存しない
 * (one_point/recommendationという最終成果物のみ)。
 *
 * focus_sub_element_keysはAIが言及した下位要素キー(最大3件程度)を保持し、
 * BrandWheelImprovementSuggestionResponseParserが実在する24キーかを検証する
 * 目的で使う(捏造防止の検証用、UI表示には使わない)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_wheel_improvement_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending/running/success/error
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->text('one_point')->nullable();
            $table->text('recommendation')->nullable();
            $table->json('focus_sub_element_keys')->nullable();
            $table->boolean('is_mock')->default(false);
            $table->string('input_hash')->nullable();
            $table->unsignedInteger('usage_input_tokens')->nullable();
            $table->unsignedInteger('usage_output_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_wheel_improvement_suggestions');
    }
};
