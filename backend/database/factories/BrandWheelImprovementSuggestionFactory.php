<?php

namespace Database\Factories;

use App\Models\Analysis;
use App\Models\BrandWheelImprovementSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrandWheelImprovementSuggestion>
 */
class BrandWheelImprovementSuggestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'status' => 'success',
            'provider' => 'mock',
            'model' => null,
            'prompt_version' => null,
            'one_point' => null,
            'recommendation' => null,
            'focus_sub_element_keys' => [],
            'is_mock' => true,
            'input_hash' => hash('sha256', $this->faker->uuid()),
            'usage_input_tokens' => null,
            'usage_output_tokens' => null,
            'duration_ms' => null,
            'error_code' => null,
            'error_message' => null,
            'generated_at' => now(),
        ];
    }
}
