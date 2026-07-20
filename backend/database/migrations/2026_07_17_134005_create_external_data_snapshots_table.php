<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_data_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_analysis_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('operation');
            $table->string('status');
            $table->string('raw_storage_path')->nullable();
            $table->jsonb('normalized_data')->nullable();
            $table->boolean('is_mock')->default(false);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            // 別Analysisで取得済みの同一ドメインデータを再利用した場合、
            // 元となったSnapshotを指す(「いつのデータを使ったか」の追跡用)。
            $table->foreignId('source_snapshot_id')->nullable()
                ->constrained('external_data_snapshots')->nullOnDelete();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['website_analysis_id', 'provider', 'operation']);
            $table->index(['provider', 'operation']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_data_snapshots');
    }
};
