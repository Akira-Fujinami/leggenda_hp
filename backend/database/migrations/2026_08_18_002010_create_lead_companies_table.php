<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 無料診断を実行した企業を、相談リクエストの有無に関わらず営業リードとして
 * 蓄積するための集約単位(管理者ダッシュボードMVP)。lead_sessionsは
 * ワンタイムトークンの発行単位(同じ企業でもトークン失効後の再訪問で別行に
 * なりうる、App\Services\Lead\LeadSessionService::createOrReuse()参照)の
 * ため企業の集約キーにできない。診断回数・初回/最終診断日はprojects/
 * analysesから都度集計するためこのテーブルには持たない(冗長化しない)。
 *
 * lead_sessions/その配下データはLEAD_RETENTION_DAYS_AFTER_EXPIRYを過ぎると
 * lead:purge-expired-sessionsで削除されうるが(projects.lead_session_idは
 * nullOnDeleteでprojects自体は残る)、このテーブルは営業台帳として
 * lead_sessionのライフサイクルから独立して永続する(意図的な設計)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_companies', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            // websites.normalized_url由来のホスト名(スキーム/パス抜き、
            // 例: aaa.co.jp)。App\Services\Lead\LeadCompanyResolver::
            // extractDomain()で抽出したもの。取得できない場合のみnull。
            $table->string('normalized_domain')->nullable();
            $table->string('primary_contact_name');
            $table->string('primary_contact_email');
            $table->string('sales_status')->default('uncontacted');
            $table->text('sales_note')->nullable();
            $table->timestamps();

            // NULLは複数行許容(Postgres/SQLiteとも標準仕様)。ドメインが
            // 取れた行同士の重複だけを防ぐ。
            $table->unique('normalized_domain');
            $table->index('company_name');
            $table->index('primary_contact_email');
            $table->index('sales_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_companies');
    }
};
