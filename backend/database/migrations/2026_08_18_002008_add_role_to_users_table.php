<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 管理者ダッシュボード(社内の営業・診断管理画面)向け。usersテーブルは
 * これまで「Project/Websiteを所有する社内ユーザー」の1種類しか区別が
 * 無かったが、社内の営業・管理者ロールを追加する。既存のSanctum認証
 * (顧客向けAPI)とは別のwebガード(セッション認証)でこのroleを見る
 * ―― 同じusersテーブルを共有するが、無料診断のリード(lead_sessions)は
 * usersテーブルに行を持たないため、admin配下へは原理的にアクセスできない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
