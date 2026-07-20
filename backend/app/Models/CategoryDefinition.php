<?php

namespace App\Models;

use Database\Factories\CategoryDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'name', 'description', 'weight', 'display_order', 'is_active'])]
class CategoryDefinition extends Model
{
    /** @use HasFactory<CategoryDefinitionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'display_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<MetricDefinition, $this>
     */
    public function metricDefinitions(): HasMany
    {
        return $this->hasMany(MetricDefinition::class, 'category_key', 'key');
    }
}
