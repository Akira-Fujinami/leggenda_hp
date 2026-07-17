<?php

namespace Database\Factories;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Analysis>
 */
class AnalysisFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'created_by' => User::factory(),
            'status' => AnalysisStatus::Pending,
            'progress' => 0,
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => ['status' => AnalysisStatus::Running, 'started_at' => now()]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => AnalysisStatus::Completed,
            'progress' => 100,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
    }
}
