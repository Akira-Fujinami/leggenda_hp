<?php

namespace App\Models;

use App\Enums\RecommendationEffort;
use App\Enums\RecommendationImpact;
use App\Enums\RecommendationPriority;
use App\Enums\RecommendationSource;
use App\Enums\RecommendationStatus;
use Database\Factories\RecommendationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'website_analysis_id', 'metric_result_id', 'category_key', 'title', 'description',
    'evidence', 'current_value', 'recommended_value', 'priority', 'impact', 'effort',
    'confidence', 'status', 'source', 'sort_score',
])]
class Recommendation extends Model
{
    /** @use HasFactory<RecommendationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'current_value' => 'array',
            'recommended_value' => 'array',
            'priority' => RecommendationPriority::class,
            'impact' => RecommendationImpact::class,
            'effort' => RecommendationEffort::class,
            'confidence' => 'decimal:2',
            'status' => RecommendationStatus::class,
            'source' => RecommendationSource::class,
            'sort_score' => 'decimal:2',
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
     * @return BelongsTo<MetricResult, $this>
     */
    public function metricResult(): BelongsTo
    {
        return $this->belongsTo(MetricResult::class);
    }
}
