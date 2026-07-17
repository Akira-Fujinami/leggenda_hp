<?php

namespace App\Models;

use App\Enums\MetricResultStatus;
use Database\Factories\MetricResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'website_analysis_id', 'analysis_page_id', 'metric_definition_id', 'raw_value', 'normalized_value',
    'score', 'max_score', 'status', 'source', 'confidence', 'evidence', 'error_code', 'error_message', 'measured_at',
])]
class MetricResult extends Model
{
    /** @use HasFactory<MetricResultFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'raw_value' => 'array',
            'normalized_value' => 'array',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'status' => MetricResultStatus::class,
            'confidence' => 'decimal:2',
            'evidence' => 'array',
            'measured_at' => 'datetime',
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
     * @return BelongsTo<AnalysisPage, $this>
     */
    public function analysisPage(): BelongsTo
    {
        return $this->belongsTo(AnalysisPage::class);
    }

    /**
     * @return BelongsTo<MetricDefinition, $this>
     */
    public function metricDefinition(): BelongsTo
    {
        return $this->belongsTo(MetricDefinition::class);
    }
}
