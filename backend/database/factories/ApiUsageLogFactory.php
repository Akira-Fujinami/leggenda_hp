<?php

namespace Database\Factories;

use App\Models\ApiUsageLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiUsageLog>
 */
class ApiUsageLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'semrush',
            'operation' => 'domain_overview',
            'analysis_id' => null,
            'website_analysis_id' => null,
            'request_hash' => $this->faker->sha256(),
            'status' => 'success',
            'http_status' => 200,
            'units_used' => 10,
            'estimated_cost' => null,
            'duration_ms' => 250,
            'error_code' => null,
        ];
    }
}
