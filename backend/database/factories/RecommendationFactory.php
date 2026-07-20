<?php

namespace Database\Factories;

use App\Enums\RecommendationEffort;
use App\Enums\RecommendationImpact;
use App\Enums\RecommendationPriority;
use App\Enums\RecommendationSource;
use App\Enums\RecommendationStatus;
use App\Models\Recommendation;
use App\Models\WebsiteAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recommendation>
 */
class RecommendationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'website_analysis_id' => WebsiteAnalysis::factory(),
            'metric_result_id' => null,
            'category_key' => 'technical_seo',
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->sentence(10),
            'evidence' => null,
            'current_value' => null,
            'recommended_value' => null,
            'priority' => RecommendationPriority::Medium,
            'impact' => RecommendationImpact::Medium,
            'effort' => RecommendationEffort::Medium,
            'confidence' => 1,
            'status' => RecommendationStatus::Open,
            'source' => RecommendationSource::Rule,
            'sort_score' => 0,
        ];
    }
}
