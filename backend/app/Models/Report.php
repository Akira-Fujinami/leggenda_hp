<?php

namespace App\Models;

use App\Enums\ReportFormat;
use App\Enums\ReportGenerationStatus;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['analysis_id', 'format', 'storage_path', 'status', 'generated_at', 'error_message'])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'format' => ReportFormat::class,
            'status' => ReportGenerationStatus::class,
            'generated_at' => 'datetime',
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
