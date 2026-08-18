<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026_08_18_002008_add_role_to_users_table.phpで追加したusers.roleを削除する。
 * 管理者ダッシュボード(/admin)の認証方式をDB上のuser.role===adminから
 * .env(ADMIN_USERNAME/ADMIN_PASSWORD_HASH)による共有アカウント方式へ
 * 変更したことに伴う(依頼者指定)。usersテーブル・role列は既存の一般
 * ユーザー認証(Api\AuthController、auth:sanctum)を含めこの管理画面
 * 機能のためだけに追加したもので、他の用途からは一切参照されていないことを
 * 確認済み(grep調査、コードコメント参照)。
 *
 * 既存のadd_role_to_users_tableマイグレーション自体は本番適用済みの
 * 可能性があるため書き換えず、新規マイグレーションでdownする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->after('email');
        });
    }
};
