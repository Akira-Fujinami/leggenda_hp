<?php

namespace App\Support\Comparison;

use App\Models\WebsiteAnalysis;
use App\Support\Scoring\WebsiteScoreResult;

readonly class SiteScoreEntry
{
    public function __construct(
        public WebsiteAnalysis $websiteAnalysis,
        public WebsiteScoreResult $score,
    ) {
    }

    public function categoryScore(string $categoryKey): float
    {
        $category = $this->score->categoryScores->firstWhere('key', $categoryKey);

        return $category?->score ?? 0.0;
    }
}
