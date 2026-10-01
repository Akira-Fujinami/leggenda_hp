<?php

namespace App\Models;

use Database\Factories\LeadCompanyDeletionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 依頼CI-3(2026-10-01): 会社単位の物理削除(App\Services\Admin\
 * LeadCompanyDeletionService)の記録。個人情報を含まない件数・サイズのみ ――
 * 会社名・ドメイン・担当者名・メール・電話・URL・ファイル名を保持する
 * 属性を一切持たない(Fillableに追加しないこと)。リレーションも持たない
 * (lead_company_idは削除済みの会社を指す数値記録であり、参照先が存在しない)。
 */
#[Fillable([
    'lead_company_id',
    'sessions_deleted_count',
    'diagnoses_deleted_count',
    'comparisons_deleted_count',
    'report_files_deleted_count',
    'attachment_files_deleted_count',
    'analysis_directories_deleted_count',
    'disk_bytes_freed',
    'file_deletion_failures_count',
])]
class LeadCompanyDeletion extends Model
{
    /** @use HasFactory<LeadCompanyDeletionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'lead_company_id' => 'integer',
            'sessions_deleted_count' => 'integer',
            'diagnoses_deleted_count' => 'integer',
            'comparisons_deleted_count' => 'integer',
            'report_files_deleted_count' => 'integer',
            'attachment_files_deleted_count' => 'integer',
            'analysis_directories_deleted_count' => 'integer',
            'disk_bytes_freed' => 'integer',
            'file_deletion_failures_count' => 'integer',
        ];
    }
}
