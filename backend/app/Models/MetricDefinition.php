<?php

namespace App\Models;

use Database\Factories\MetricDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'key', 'category_key', 'name', 'description', 'value_type', 'unit',
    'source_type', 'scoring_type', 'weight', 'max_score', 'higher_is_better',
    'minimum_value', 'target_value', 'maximum_value', 'thresholds', 'is_required',
    'not_found_policy', 'not_found_partial_rate', 'recommendation_template',
    'is_active', 'display_order',
])]
class MetricDefinition extends Model
{
    /** @use HasFactory<MetricDefinitionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'max_score' => 'decimal:2',
            'higher_is_better' => 'boolean',
            'minimum_value' => 'decimal:2',
            'target_value' => 'decimal:2',
            'maximum_value' => 'decimal:2',
            // scoring_type/not_found_policyはあえて素の文字列のまま扱う。
            // MetricScorer::resolveScoringType()/resolveNotFoundPolicy()が
            // tryFrom()で安全に解釈する(不正値でも500にしないため)。
            'thresholds' => 'array',
            'is_required' => 'boolean',
            'not_found_partial_rate' => 'decimal:2',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<MetricResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(MetricResult::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<CategoryDefinition, $this>
     */
    public function categoryDefinition(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CategoryDefinition::class, 'category_key', 'key');
    }
}
