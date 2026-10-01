<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 依頼CJ-1(2026-10-01): 「比較かどうか」をanalyses.source_analysis_idの
 * 有無で判定していた約20箇所(実働コードは13箇所)を、明示的なkind列へ
 * 置き換える。デプロイ手順: このマイグレーションは新しいアプリケーション
 * コードより必ず先に適用すること。下のbackfillを省略/スキップすると、
 * 既存の起点ありの比較がすべて既定値(lead_diagnosis)のまま誤分類され、
 * ダッシュボードの件数・比較一覧・「3〜5社で比較する」ボタンの表示が
 * 壊れる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->string('kind')->default('lead_diagnosis')->after('source_analysis_id');
            $table->index('kind');
        });

        // 依頼CJ-1で唯一許可されたデータ更新: 既存の
        // 「source_analysis_idが非nullなら比較」という判定を、新しいkind列へ
        // 一度だけ引き継ぐ。
        DB::table('analyses')->whereNotNull('source_analysis_id')->update(['kind' => 'admin_comparison']);
    }

    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
