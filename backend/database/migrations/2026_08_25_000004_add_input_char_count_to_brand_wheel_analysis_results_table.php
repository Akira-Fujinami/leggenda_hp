<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 依頼P-1(2026-08-25、依頼Oの続き)。ReportViewModelBuilderの
 * $selfLowContentNotice(自社の合計matched件数が閾値未満のときの但し書き)が、
 * 「本文が少なかった」と断定できるかどうかを、実際の入力文字数
 * (GenerateBrandWheelAnalysisJob::inputTotalChars()、isInputInsufficient()と
 * 同じ算出式)で判定するために追加する列。
 *
 * 依頼Oの調査で、代用できそうに見えたusage_input_tokensはキャッシュ再利用
 * (同一input_hashの過去成功結果を使い回す)経路で0固定になり判定材料として
 * 使えないことが判明したため、専用の列を持つ。
 *
 * 既存行はnullのまま(遡及計算はしない、依頼者指定)。ReportViewModelBuilder
 * 側は「input_char_countがnullのときは(a)/(b)いずれの文言も出さない
 * (判定材料が無いときに推測で断定しない)」という規則で扱う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_wheel_analysis_results', function (Blueprint $table) {
            $table->unsignedInteger('input_char_count')->nullable()->after('input_truncated');
        });
    }

    public function down(): void
    {
        Schema::table('brand_wheel_analysis_results', function (Blueprint $table) {
            $table->dropColumn('input_char_count');
        });
    }
};
