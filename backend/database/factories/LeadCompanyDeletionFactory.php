<?php

namespace Database\Factories;

use App\Models\LeadCompanyDeletion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadCompanyDeletion>
 */
class LeadCompanyDeletionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_company_id' => $this->faker->numberBetween(1, 100000),
            'sessions_deleted_count' => 0,
            'diagnoses_deleted_count' => 0,
            'comparisons_deleted_count' => 0,
            'report_files_deleted_count' => 0,
            'attachment_files_deleted_count' => 0,
            'analysis_directories_deleted_count' => 0,
            'disk_bytes_freed' => 0,
            'file_deletion_failures_count' => 0,
        ];
    }
}
