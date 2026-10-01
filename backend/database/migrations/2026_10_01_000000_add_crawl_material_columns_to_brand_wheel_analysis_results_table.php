<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 依頼CH-1a(2026-10-01)。依頼CG-1の本番実測(analysis_id=148)で、判定は
 * 成立した(status=success)のに材料が空同然(547段落・起点URL配下由来の
 * クロール文字数0)で0/24がついたケースが確認された。資料側(管理画面・
 * 差し込みスライド・比較レポートPDF)が「材料が薄いので数字を文言に
 * 置き換える」判断をできるよう、採用段落の内訳を記録する2列を追加する。
 *
 * 【依頼CH追補-1(2026-10-01)による定義の訂正】当初このdocblockは
 * 「『採用された文字数の合計』は既存のinput_char_countと一致するため
 * 新列を作らない」としていたが誤りだった ―― input_char_countは起点ページ
 * 本文＋クロール分＋両者をつなぐ改行を含む値であり、信金中央金庫の実例
 * (起点URL配下のクロール文字数0、起点ページ本文のみでも閾値を超えうる)
 * では「材料が薄い」ことを検出できないことが判明した。
 *
 * input_origin_chars(訂正後の定義): 起点由来の文字数 ―― 起点ページ
 * (採用ページ・トップページ)本文の段落合計 ＋ 起点URL配下のクロール段落
 * 合計(段落間の改行は含まない、BrandWheelAnalysisInputFactory::
 * seedParagraphChars()参照)。crawl_site=falseでも起点ページ本文は数える
 * ―― 巡回が空振りしただけの健全なサイトを誤って材料不足にしないため。
 * 既存行はnull(遡及計算しない、依頼者指定)。新規に生成される行は
 * (status=insufficient_input/error/successいずれでも)必ず値を持つ。
 *
 * input_adopted_paragraph_count: クロール由来の採用段落の総数
 * (selected_paragraph_length.count)。クロール由来の候補が無いときnull
 * (この列の定義は追補の対象外、変更していない)。
 *
 * App\Services\BrandWheel\BrandWheelMaterialSufficiencyがinput_origin_chars
 * (input_char_countではない)を参照する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_wheel_analysis_results', function (Blueprint $table) {
            $table->unsignedInteger('input_origin_chars')->nullable()->after('input_char_count');
            $table->unsignedInteger('input_adopted_paragraph_count')->nullable()->after('input_origin_chars');
        });
    }

    public function down(): void
    {
        Schema::table('brand_wheel_analysis_results', function (Blueprint $table) {
            $table->dropColumn(['input_origin_chars', 'input_adopted_paragraph_count']);
        });
    }
};
