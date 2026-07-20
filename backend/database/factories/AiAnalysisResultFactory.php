<?php

namespace Database\Factories;

use App\Models\AiAnalysisResult;
use App\Models\Analysis;
use App\Models\WebsiteAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiAnalysisResult>
 */
class AiAnalysisResultFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'website_analysis_id' => WebsiteAnalysis::factory(),
            'provider' => 'mock',
            'model' => null,
            'status' => 'success',
            'summary' => $this->faker->sentence(),
            'strengths' => [],
            'weaknesses' => [],
            'priority_actions' => [],
            'competitor_insights' => [],
            'cautions' => [],
            'confidence' => 0.0,
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
