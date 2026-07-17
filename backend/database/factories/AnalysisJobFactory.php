<?php

namespace Database\Factories;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Models\Analysis;
use App\Models\AnalysisJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalysisJob>
 */
class AnalysisJobFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'website_analysis_id' => null,
            'job_type' => JobType::FetchStaticPage,
            'queue_name' => 'analysis',
            'status' => AnalysisJobStatus::Pending,
            'progress' => 0,
            'attempts' => 0,
        ];
    }
}
