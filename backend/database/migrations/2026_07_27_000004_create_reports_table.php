<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * リード向け簡易診断のWord/PDFレポート。1 Analysis につき最大2行
 * (format=docx/pdf)。実体ファイルはStorageに保存し、このテーブルは
 * メタデータのみを持つ ―― ダウンロードは必ずlead.token配下の認証済み
 * ストリーミングエンドポイント経由とし、storage_pathを直接公開しない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained()->cascadeOnDelete();
            $table->string('format');
            $table->string('storage_path');
            $table->string('status');
            $table->timestamp('generated_at')->nullable();
            // 内部調査用のみ。lead向けレスポンスに含めてはならない。
            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->unique(['analysis_id', 'format']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
