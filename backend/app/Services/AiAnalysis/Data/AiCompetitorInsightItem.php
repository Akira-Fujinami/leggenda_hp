<?php

namespace App\Services\AiAnalysis\Data;

readonly class AiCompetitorInsightItem
{
    /**
     * @param  list<int>  $competitorWebsiteAnalysisIds  AiAnalysisInput::competitorGapsに実在するIDのみ
     */
    public function __construct(
        public string $title,
        public string $description,
        public array $competitorWebsiteAnalysisIds = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'competitor_website_analysis_ids' => $this->competitorWebsiteAnalysisIds,
        ];
    }
}
