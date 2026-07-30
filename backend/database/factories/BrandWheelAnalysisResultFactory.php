<?php

namespace Database\Factories;

use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\WebsiteAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrandWheelAnalysisResult>
 */
class BrandWheelAnalysisResultFactory extends Factory
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
            'prompt_version' => null,
            'axes' => [],
            'core_value_readable' => false,
            'core_value_evidence' => null,
            'quality_dimension_notes' => [],
            'cautions' => [],
            'axis_state_counts' => ['read' => 0, 'partial' => 0, 'unread' => 0],
            'is_mock' => true,
            'input_hash' => hash('sha256', $this->faker->uuid()),
            'input_truncated' => false,
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            'usage_input_tokens' => null,
            'usage_output_tokens' => null,
            'duration_ms' => null,
            'error_code' => null,
            'error_message' => null,
            'generated_at' => now(),
            'staff_notified_at' => null,
            'lead_notified_at' => null,
        ];
    }
}
