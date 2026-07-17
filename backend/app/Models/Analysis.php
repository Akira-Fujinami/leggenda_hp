<?php

namespace App\Models;

use App\Enums\AnalysisStatus;
use Database\Factories\AnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'created_by', 'status', 'progress', 'started_at', 'completed_at', 'failed_at', 'error_summary'])]
class Analysis extends Model
{
    /** @use HasFactory<AnalysisFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => AnalysisStatus::class,
            'progress' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<WebsiteAnalysis, $this>
     */
    public function websiteAnalyses(): HasMany
    {
        return $this->hasMany(WebsiteAnalysis::class);
    }

    /**
     * @return HasMany<AnalysisJob, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(AnalysisJob::class);
    }
}
