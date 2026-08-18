<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * projects.lead_session_idと同様の理由でnullOnDeleteにする(既存の
 * lead_session_idカラム追加マイグレーションのコメント参照)。lead_company_id
 * はprojects.lead_session_idと違い、リード獲得フォーム経由のprojectだけが
 * 対象(社内ユーザーが直接作成したprojectはnullのまま)。診断企業の
 * 集約(App\Services\Lead\LeadCompanyResolver)はここへ直接紐付けることで、
 * lead_sessionが将来purgeされても診断履歴の集計から漏れないようにする
 * (lead_companiesマイグレーションのコメント参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('lead_company_id')->nullable()->after('lead_session_id')->constrained()->nullOnDelete();
            $table->index(['lead_company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_company_id');
        });
    }
};
