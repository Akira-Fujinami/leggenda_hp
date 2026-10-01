<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 依頼CJ-2(2026-10-01): 無料診断を経由しない比較作成では、担当者名・
 * メールアドレスを一切収集しない(依頼者指定)。既存のcreate_lead_companies_table
 * ではこの2列がNOT NULLだったため、nullableに変更する。
 *
 * 本番(Postgres)・テスト(SQLite、phpunit.xmlでDB_CONNECTION=sqlite)の
 * 両方で動く必要があるため、Schema::table()->change()を使う(Laravel 11以降は
 * doctrine/dbal無しでも主要なカラム変更をネイティブに生成できる、
 * composer.jsonにdoctrine/dbalは入っていないことを確認済み)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_companies', function (Blueprint $table) {
            $table->string('primary_contact_name')->nullable()->change();
            $table->string('primary_contact_email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // NULL行が既に存在する状態でのdownは失敗しうるが、依頼で許可された
        // データ更新の範囲を広げないため、ここでの自動補完は行わない。
        Schema::table('lead_companies', function (Blueprint $table) {
            $table->string('primary_contact_name')->nullable(false)->change();
            $table->string('primary_contact_email')->nullable(false)->change();
        });
    }
};
