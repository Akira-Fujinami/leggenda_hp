<?php

namespace Database\Factories;

use App\Models\CategoryDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryDefinition>
 */
class CategoryDefinitionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'category_'.$this->faker->unique()->word().$this->faker->unique()->randomNumber(5),
            'name' => $this->faker->words(2, true),
            'description' => null,
            'weight' => 10,
            'display_order' => 0,
            'is_active' => true,
        ];
    }
}
