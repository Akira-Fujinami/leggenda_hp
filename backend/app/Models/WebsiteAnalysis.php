<?php

namespace App\Models;

use App\Enums\WebsiteAnalysisStatus;
use Database\Factories\WebsiteAnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'analysis_id', 'website_id', 'status', 'progress', 'started_at', 'completed_at',
    'error_summary', 'http_status', 'final_url', 'response_time_ms',
])]
class WebsiteAnalysis extends Model
{
    /** @use HasFactory<WebsiteAnalysisFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => WebsiteAnalysisStatus::class,
            'progress' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * @return HasMany<AnalysisJob, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(AnalysisJob::class);
    }

    /**
     * @return HasMany<AnalysisPage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(AnalysisPage::class);
    }

    /**
     * @return HasMany<MetricResult, $this>
     */
    public function metricResults(): HasMany
    {
        return $this->hasMany(MetricResult::class);
    }

    /**
     * @return HasMany<Screenshot, $this>
     */
    public function screenshots(): HasMany
    {
        return $this->hasMany(Screenshot::class);
    }

    public function homepage(): HasOne
    {
        return $this->hasOne(AnalysisPage::class)->where('page_type', 'homepage');
    }
}
