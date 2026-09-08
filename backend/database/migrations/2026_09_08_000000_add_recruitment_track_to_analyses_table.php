<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 依頼BB(新卒採用・キャリア採用の区別)。診断単位の属性のため、
 * lead_sessions(同じリードが別の区分で再診断しうる)ではなくanalysesに
 * 持たせる。値は 'unspecified'(既定)/'new_graduate'/'career'。既定値
 * 'unspecified'では、この列を参照する新しい分岐(巡回時の除外・レポート
 * 表紙の一文)はいずれも旧挙動と完全に同じになる ―― 選ばなかった場合に
 * 挙動が変わらないことの保証はこの既定値そのものが担う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->string('recruitment_track')->default('unspecified')->after('crawl_site');
        });
    }

    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->dropColumn('recruitment_track');
        });
    }
};
