<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('operation');
            $table->foreignId('analysis_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('website_analysis_id')->nullable()->constrained()->nullOnDelete();
            // 同一リクエストパラメータの重複検知・キャッシュ照合用ハッシュ。
            // APIキーやレスポンス本文は含めない。
            $table->string('request_hash');
            $table->string('status');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('units_used')->nullable();
            $table->decimal('estimated_cost', 8, 4)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['provider', 'created_at']);
            $table->index('request_hash');
            $table->index(['provider', 'operation', 'request_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage_logs');
    }
};
