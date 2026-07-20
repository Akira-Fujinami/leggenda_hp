<?php

namespace Database\Factories;

use App\Models\CategoryDefinition;
use App\Models\MetricDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricDefinition>
 */
class MetricDefinitionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'metric_'.$this->faker->unique()->word().$this->faker->unique()->randomNumber(5),
            'category_key' => CategoryDefinition::query()->inRandomOrder()->value('key')
                ?? CategoryDefinition::factory()->create()->key,
            'name' => $this->faker->sentence(3),
            'description' => null,
            'value_type' => 'boolean',
            'unit' => null,
            'source_type' => 'html',
            'scoring_type' => 'boolean',
            'weight' => 1,
            'max_score' => 5,
            'higher_is_better' => true,
            'minimum_value' => null,
            'target_value' => null,
            'maximum_value' => null,
            'thresholds' => null,
            'is_required' => false,
            'not_found_policy' => 'zero',
            'not_found_partial_rate' => null,
            'recommendation_template' => null,
            'is_active' => true,
            'display_order' => 0,
        ];
    }
}
