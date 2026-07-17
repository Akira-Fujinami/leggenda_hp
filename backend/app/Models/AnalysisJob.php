<?php

namespace App\Models;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use Database\Factories\AnalysisJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'analysis_id', 'website_analysis_id', 'job_type', 'queue_name', 'status', 'progress',
    'attempts', 'started_at', 'completed_at', 'failed_at', 'error_code', 'error_message', 'metadata',
])]
class AnalysisJob extends Model
{
    /** @use HasFactory<AnalysisJobFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'job_type' => JobType::class,
            'status' => AnalysisJobStatus::class,
            'progress' => 'integer',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Analysis, $this>
     */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    /**
     * @return BelongsTo<WebsiteAnalysis, $this>
     */
    public function websiteAnalysis(): BelongsTo
    {
        return $this->belongsTo(WebsiteAnalysis::class);
    }
}
