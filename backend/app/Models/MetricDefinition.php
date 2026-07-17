<?php

namespace App\Models;

use Database\Factories\MetricDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'key', 'category', 'name', 'description', 'value_type', 'unit',
    'source_type', 'max_score', 'is_active', 'display_order',
])]
class MetricDefinition extends Model
{
    /** @use HasFactory<MetricDefinitionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'max_score' => 'decimal:2',
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
}
