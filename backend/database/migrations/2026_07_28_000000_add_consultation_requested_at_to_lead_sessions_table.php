<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_sessions', function (Blueprint $table) {
            // nullのままなら未リクエスト。一度セットしたら以後の相談ボタン
            // 押下はこの列の存在(NOT NULL)だけで弾く(二重送信防止、
            // 条件付きUPDATEで競合安全に更新する)。
            $table->timestamp('consultation_requested_at')->nullable()->after('analyses_used');
        });
    }

    public function down(): void
    {
        Schema::table('lead_sessions', function (Blueprint $table) {
            $table->dropColumn('consultation_requested_at');
        });
    }
};
