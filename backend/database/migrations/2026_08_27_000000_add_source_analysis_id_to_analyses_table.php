<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 依頼AB-2(2026-08-27): 管理者起点の多社比較(自社+競合3〜5社)が、
 * どの無料診断から作られたかを明示的に記録する。
 *
 * サイト数からの暗黙の判別(競合3件以上なら比較、等)は採らない
 * (依頼者指定 ―― 比較であることと起点の診断がどれかは、常にこのカラムで
 * 明示的に分かる状態にする)。自己参照(analyses.id)、nullOnDelete ――
 * 起点の無料診断が削除されても比較自体は壊れず、単にリンクが外れる
 * だけにする(projects.lead_session_id等、既存の同種カラムと同じ方針)。
 * 通常の無料診断(比較の起点ではない、比較でもない)は常にnull。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->foreignId('source_analysis_id')->nullable()->after('project_id')
                ->constrained('analyses')->nullOnDelete();
            $table->index('source_analysis_id');
        });
    }

    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_analysis_id');
        });
    }
};
