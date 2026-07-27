<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * リードが自己診断で生成したProjectを、社内ユーザーが作成したものと
 * 区別するための紐付け。nullOnDeleteとし、lead_sessionを削除しても
 * Projectは即座には消さない(削除自体はlead:purge-expired-sessions
 * コマンドが明示的なトランザクションで行う)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('lead_session_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_session_id');
        });
    }
};
