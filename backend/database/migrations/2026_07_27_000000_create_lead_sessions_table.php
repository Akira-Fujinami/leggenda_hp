<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * リード獲得フォームから発行されるワンタイムセッション。ログイン概念を持つ
 * Userとは完全に別管理とする(既存のProjectPolicy等のownershipチェックへ
 * 影響を与えないため)。tokenは平文で保持せずhash('sha256', ...)のみ保存する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('contact_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('industry')->nullable();
            $table->string('employee_range')->nullable();
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('analyses_used')->default(0);
            $table->timestamps();

            $table->index('email');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_sessions');
    }
};
