<?php

namespace Database\Factories;

use App\Models\LeadSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadSession>
 */
class LeadSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_name' => $this->faker->company(),
            'contact_name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->optional()->phoneNumber(),
            'industry' => $this->faker->optional()->word(),
            'employee_range' => $this->faker->optional()->randomElement(['1-10', '11-50', '51-100']),
            'token_hash' => hash('sha256', $this->faker->unique()->uuid()),
            'expires_at' => now()->addDays(7),
            'analyses_used' => 0,
        ];
    }
}
