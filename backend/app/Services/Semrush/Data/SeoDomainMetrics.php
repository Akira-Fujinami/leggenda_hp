<?php

namespace App\Services\Semrush\Data;

readonly class SeoDomainMetrics
{
    public function __construct(
        public ?float $authorityScore = null,
        public ?int $organicTrafficEstimate = null,
        public ?int $organicKeywordsCount = null,
        public ?bool $paidSearchPresent = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'authority_score' => $this->authorityScore,
            'organic_traffic_estimate' => $this->organicTrafficEstimate,
            'organic_keywords_count' => $this->organicKeywordsCount,
            'paid_search_present' => $this->paidSearchPresent,
        ];
    }
}
