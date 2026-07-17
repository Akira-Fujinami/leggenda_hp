<?php

namespace App\Models;

use App\Enums\PageType;
use Database\Factories\AnalysisPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'website_analysis_id', 'url', 'final_url', 'page_type', 'http_status', 'content_type',
    'raw_html_path', 'rendered_html_path', 'title', 'meta_description', 'h1_count', 'word_count', 'fetched_at',
])]
class AnalysisPage extends Model
{
    /** @use HasFactory<AnalysisPageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'page_type' => PageType::class,
            'http_status' => 'integer',
            'h1_count' => 'integer',
            'word_count' => 'integer',
            'fetched_at' => 'datetime',
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
     * @return HasMany<MetricResult, $this>
     */
    public function metricResults(): HasMany
    {
        return $this->hasMany(MetricResult::class);
    }
}
