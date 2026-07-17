<?php

namespace Database\Factories;

use App\Enums\MetricResultStatus;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\WebsiteAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricResult>
 */
class MetricResultFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'website_analysis_id' => WebsiteAnalysis::factory(),
            'analysis_page_id' => null,
            'metric_definition_id' => MetricDefinition::factory(),
            'raw_value' => null,
            'normalized_value' => null,
            'score' => 0,
            'max_score' => 0,
            'status' => MetricResultStatus::Success,
            'source' => null,
            'confidence' => null,
            'evidence' => null,
            'error_code' => null,
            'error_message' => null,
            'measured_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => MetricResultStatus::Error,
            'score' => null,
            'error_code' => 'PARSE_FAILED',
            'error_message' => 'failed to parse',
        ]);
    }

    public function unavailable(): static
    {
        return $this->state(fn () => [
            'status' => MetricResultStatus::Unavailable,
            'score' => null,
        ]);
    }
}
