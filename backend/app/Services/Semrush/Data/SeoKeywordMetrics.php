<?php

namespace App\Services\Semrush\Data;

readonly class SeoKeywordMetrics
{
    public function __construct(
        public ?int $top3KeywordsCount = null,
        public ?int $top10KeywordsCount = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'top3_keywords_count' => $this->top3KeywordsCount,
            'top10_keywords_count' => $this->top10KeywordsCount,
        ];
    }
}
