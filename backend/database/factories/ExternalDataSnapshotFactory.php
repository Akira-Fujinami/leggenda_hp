<?php

namespace Database\Factories;

use App\Models\ExternalDataSnapshot;
use App\Models\WebsiteAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalDataSnapshot>
 */
class ExternalDataSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'website_analysis_id' => WebsiteAnalysis::factory(),
            'provider' => 'mock',
            'operation' => 'domain_overview',
            'status' => 'success',
            'raw_storage_path' => null,
            'normalized_data' => null,
            'is_mock' => true,
            'fetched_at' => now(),
            'expires_at' => now()->addDay(),
            'source_snapshot_id' => null,
            'error_code' => null,
            'error_message' => null,
        ];
    }
}
