<?php

namespace Database\Factories;

use App\Models\LeadCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadCompany>
 */
class LeadCompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_name' => $this->faker->company(),
            'normalized_domain' => $this->faker->unique()->domainName(),
            'primary_contact_name' => $this->faker->name(),
            'primary_contact_email' => $this->faker->unique()->safeEmail(),
            'sales_status' => 'uncontacted',
            'sales_note' => null,
        ];
    }
}
