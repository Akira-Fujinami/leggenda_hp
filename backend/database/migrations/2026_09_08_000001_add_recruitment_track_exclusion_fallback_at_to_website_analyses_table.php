<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 依頼BB-2の安全弁用。除外(analyses.recruitment_track起点)を適用した結果
 * fetched件数が閾値を下回ったサイト(自社・競合いずれか)だけを、以後の
 * 除外を止めて再開するためのマーカー。self/competitorで結果が異なりうる
 * ため(依頼BBの安全弁は「除外しすぎた側」だけ元に戻す設計、実装報告参照)、
 * analysesではなくwebsite_analyses(サイト単位)に持たせる。
 *
 * nullのまま(既定)なら、この列を参照する新しい分岐は一切発火せず、
 * 巡回の挙動は旧来と完全に同じになる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_analyses', function (Blueprint $table) {
            $table->timestamp('recruitment_track_exclusion_fallback_at')->nullable()->after('brand_wheel_dispatched_at');
        });
    }

    public function down(): void
    {
        Schema::table('website_analyses', function (Blueprint $table) {
            $table->dropColumn('recruitment_track_exclusion_fallback_at');
        });
    }
};
