<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3の採点エンジン向けにmetric_definitionsを拡張する。
 *
 * 'category' -> 'category_key' へのリネームはCategoryDefinition.keyとの
 * 対応を明確にするためのもので、metric_results側はmetric_definition_id
 * (数値FK)でしか紐付いていないため、既存のMetricResult・分析結果には
 * 一切影響しない(スキーマ上は参照整合性が保たれたまま列名のみ変更)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_definitions', function (Blueprint $table) {
            $table->renameColumn('category', 'category_key');
        });

        Schema::table('metric_definitions', function (Blueprint $table) {
            $table->string('scoring_type')->default('boolean')->after('source_type');
            // weightは採点(max_score)とは別に、改善提案の優先度計算(metric_weight)に
            // 使う相対重要度。max_scoreは引き続き実採点で使う配点。
            $table->decimal('weight', 5, 2)->default(1)->after('scoring_type');
            $table->boolean('higher_is_better')->default(true)->after('max_score');
            $table->decimal('minimum_value', 12, 2)->nullable()->after('higher_is_better');
            $table->decimal('target_value', 12, 2)->nullable()->after('minimum_value');
            $table->decimal('maximum_value', 12, 2)->nullable()->after('target_value');
            $table->jsonb('thresholds')->nullable()->after('maximum_value');
            $table->boolean('is_required')->default(false)->after('thresholds');
            // not_found時の扱い: zero(0点扱いで採点対象) / exclude(分母から除外) /
            // partial(not_found_partial_rateに応じた部分点)。
            $table->string('not_found_policy')->default('zero')->after('is_required');
            $table->decimal('not_found_partial_rate', 3, 2)->nullable()->after('not_found_policy');
            $table->text('recommendation_template')->nullable()->after('not_found_partial_rate');
        });

        Schema::table('metric_definitions', function (Blueprint $table) {
            $table->foreign('category_key')->references('key')->on('category_definitions')->restrictOnDelete();
            $table->index('category_key');
        });
    }

    public function down(): void
    {
        Schema::table('metric_definitions', function (Blueprint $table) {
            $table->dropForeign(['category_key']);
            $table->dropColumn([
                'scoring_type', 'weight', 'higher_is_better', 'minimum_value', 'target_value',
                'maximum_value', 'thresholds', 'is_required', 'not_found_policy',
                'not_found_partial_rate', 'recommendation_template',
            ]);
        });

        Schema::table('metric_definitions', function (Blueprint $table) {
            $table->renameColumn('category_key', 'category');
        });
    }
};
