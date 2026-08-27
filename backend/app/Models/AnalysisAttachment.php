<?php

namespace App\Models;

use Database\Factories\AnalysisAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 依頼AD-1(2026-08-27): 診断(Analysis)に紐づく、商談相手ごとの既存資料
 * (フォーマット未確定)。original_filenameは表示・ダウンロード時のファイル名
 * にのみ使う ―― storage_pathの組み立てには一切使わない
 * (AnalysisAttachmentService::store()参照)。
 */
#[Fillable(['analysis_id', 'original_filename', 'storage_path', 'extension', 'mime_type', 'size_bytes'])]
class AnalysisAttachment extends Model
{
    /** @use HasFactory<AnalysisAttachmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Analysis, $this>
     */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }
}
