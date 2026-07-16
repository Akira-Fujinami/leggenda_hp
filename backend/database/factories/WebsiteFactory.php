<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Website>
 */
class WebsiteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = Str::slug($this->faker->unique()->domainWord()).'.example.com';

        return [
            'project_id' => Project::factory(),
            'name' => $this->faker->company(),
            'url' => "https://{$domain}",
            'normalized_url' => "https://{$domain}",
            'is_primary' => false,
            'display_order' => 0,
        ];
    }
}
