<?php

namespace Database\Factories;

use App\Enums\PageType;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalysisPage>
 */
class AnalysisPageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'website_analysis_id' => WebsiteAnalysis::factory(),
            'url' => 'https://example.com',
            'page_type' => PageType::Homepage,
            'http_status' => 200,
        ];
    }
}
