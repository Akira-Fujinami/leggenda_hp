<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * リード向けレポート「候補者に与える印象」ページ改修(2026-08-17)。
 * 既存のimpression(候補者が受け取る印象の短いフレーズ列挙、画面/APIの
 * 後方互換のため維持)に加え、レポート表示用にポジティブ/ネガティブの
 * 印象を1〜2文ずつ生成する(OpenAiBrandWheelAnalysisProvider PROMPT_VERSION
 * v8〜)。key_message/impressionと同じく、個別の主張ではなくページ全体から
 * 読み取れる総合的な要約のためevidence実在検証の対象外、社外に出る文章の
 * ためforbidden_phrasesチェックのみ適用する(BrandWheelAnalysisResponseParser参照)。
 *
 * PROMPT_VERSIONを合わせて上げるため、既存の成功結果(この2列がnullのまま)は
 * input_hashの再利用対象から自動的に外れる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_wheel_analysis_results', function (Blueprint $table) {
            $table->text('positive_impression')->nullable()->after('impression');
            $table->text('negative_impression')->nullable()->after('positive_impression');
        });
    }

    public function down(): void
    {
        Schema::table('brand_wheel_analysis_results', function (Blueprint $table) {
            $table->dropColumn(['positive_impression', 'negative_impression']);
        });
    }
};
