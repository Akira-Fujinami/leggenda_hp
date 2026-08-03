<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * リード向け画面の紺帯(「このページの内容から想像するキーメッセージと印象」)
 * に対応する2項目(2026-08-03)。key_message/impressionはevidence実在検証の
 * 対象外(下位要素のような「原文にこの語句があるか」という個別の主張ではなく、
 * サイト全体から読み取れる総合的な要約・印象のため)。impressionは社外に
 * 出る文章のため、config('brand_wheel.forbidden_phrases')を含む場合はnullに
 * する(BrandWheelAnalysisResponseParser参照)。
 *
 * PROMPT_VERSIONを合わせて上げる(出力構造が変わるため)ので、既存の成功
 * 結果(この2列がnullのまま)はinput_hashの再利用対象から自動的に外れる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_wheel_analysis_results', function (Blueprint $table) {
            $table->text('key_message')->nullable()->after('core_value_evidence');
            $table->text('impression')->nullable()->after('key_message');
        });
    }

    public function down(): void
    {
        Schema::table('brand_wheel_analysis_results', function (Blueprint $table) {
            $table->dropColumn(['key_message', 'impression']);
        });
    }
};
