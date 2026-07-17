<?php

namespace Database\Factories;

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
            'category' => 'technical_seo',
            'name' => $this->faker->sentence(3),
            'description' => null,
            'value_type' => 'boolean',
            'unit' => null,
            'source_type' => 'html',
            'max_score' => 5,
            'is_active' => true,
            'display_order' => 0,
        ];
    }
}
