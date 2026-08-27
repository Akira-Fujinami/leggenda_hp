<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 依頼AD-1(2026-08-27): 商談相手ごとに用意された既存資料(フォーマット未確定、
 * PPTX/PDF/DOCX等)を、診断(Analysis)に対して1件アップロードできるように
 * する。無料診断・多社比較のどちらにも付けられる(Analysisに対して1本の
 * リレーションのため、区別する実装は不要)。
 *
 * original_filename(クライアントが送ってきたファイル名)は表示・ダウンロード
 * 時のファイル名にのみ使い、保存パス(storage_path)には一切使わない
 * (保存名はUUID、AnalysisAttachmentService::store()参照)。
 *
 * 【1診断あたりの上限】現時点では1件のみをサポートする(依頼者の想定どおり)。
 * DBスキーマとしては複数件を許す設計(analysis_idへのunique制約を付けない)
 * にしておき、上限の強制はアプリケーション層(AnalysisAttachmentService、
 * アップロード時に既存の1件を削除してから差し替える「単一スロット」運用)に
 * 留める ―― 将来複数件に対応する場合、マイグレーション不要でアプリ層の
 * 制約を外すだけで済む(依頼者への提案、報告参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('extension', 16);
            // finfo(マジックバイト)で検出した実際のMIMEタイプ。クライアントの
            // Content-Typeヘッダ(容易に偽装できる)は保持しない。
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->index('analysis_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_attachments');
    }
};
