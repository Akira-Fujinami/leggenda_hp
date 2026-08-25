<?php

namespace Database\Factories;

use App\Models\AnalysisCrawledPage;
use App\Models\WebsiteAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalysisCrawledPage>
 */
class AnalysisCrawledPageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'website_analysis_id' => WebsiteAnalysis::factory(),
            'url' => 'https://example.com/'.$this->faker->unique()->slug(),
            'http_status' => 200,
            'depth' => 1,
            'discovered_via' => 'link',
        ];
    }
}
