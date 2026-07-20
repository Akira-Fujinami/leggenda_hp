<?php

namespace App\Models;

use Database\Factories\ExternalDataSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'website_analysis_id', 'provider', 'operation', 'domain', 'database', 'status', 'raw_storage_path',
    'normalized_data', 'is_mock', 'fetched_at', 'expires_at', 'source_snapshot_id',
    'error_code', 'error_message',
])]
class ExternalDataSnapshot extends Model
{
    /** @use HasFactory<ExternalDataSnapshotFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'normalized_data' => 'array',
            'is_mock' => 'boolean',
            'fetched_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebsiteAnalysis, $this>
     */
    public function websiteAnalysis(): BelongsTo
    {
        return $this->belongsTo(WebsiteAnalysis::class);
    }

    /**
     * @return BelongsTo<ExternalDataSnapshot, $this>
     */
    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_snapshot_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
