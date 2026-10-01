<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 依頼CI-3(2026-10-01): 管理画面からの会社単位の物理削除
 * (App\Services\Admin\LeadCompanyDeletionService)の記録。個人情報を含まない
 * 記録だけを残す(依頼者指定、必須) ―― 会社名・ドメイン・担当者名・
 * メールアドレス・電話番号・URL・添付資料のファイル名はいずれも保持しない
 * (ハッシュにしても残さない、ドメインは推測で復元できるため)。
 *
 * lead_company_idへの外部キー制約は付けない ―― 削除が完了した時点で参照先の
 * lead_companies行は既に存在しないため、数値として「かつて何番だったか」を
 * 残すだけの記録専用フィールドとする。
 *
 * 管理画面でこの記録を閲覧する画面はこの依頼では作らない(依頼者指定)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_company_deletions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_company_id');
            $table->unsignedInteger('sessions_deleted_count');
            // lead_session_idが非nullだったProject(無料診断)の件数。
            $table->unsignedInteger('diagnoses_deleted_count');
            // lead_session_idがnullだったProject(多社比較、
            // AdminComparisonServiceが作るもの)の件数。
            $table->unsignedInteger('comparisons_deleted_count');
            $table->unsignedInteger('report_files_deleted_count');
            $table->unsignedInteger('attachment_files_deleted_count');
            $table->unsignedInteger('analysis_directories_deleted_count');
            $table->unsignedBigInteger('disk_bytes_freed');
            $table->unsignedInteger('file_deletion_failures_count');
            $table->timestamps();

            $table->index('lead_company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_company_deletions');
    }
};
