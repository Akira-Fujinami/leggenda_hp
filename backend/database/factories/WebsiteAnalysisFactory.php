<?php

namespace Database\Factories;

use App\Enums\WebsiteAnalysisStatus;
use App\Models\Analysis;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebsiteAnalysis>
 */
class WebsiteAnalysisFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'website_id' => Website::factory(),
            'status' => WebsiteAnalysisStatus::Pending,
            'progress' => 0,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => WebsiteAnalysisStatus::Completed,
            'progress' => 100,
            'started_at' => now()->subMinutes(3),
            'completed_at' => now(),
        ]);
    }
}
