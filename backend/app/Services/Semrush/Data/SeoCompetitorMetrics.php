<?php

namespace App\Services\Semrush\Data;

readonly class SeoCompetitorMetrics
{
    /**
     * @param  list<string>  $topCompetitorDomains
     */
    public function __construct(
        public ?int $competitorDomainsCount = null,
        public array $topCompetitorDomains = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'competitor_domains_count' => $this->competitorDomainsCount,
            'top_competitor_domains' => $this->topCompetitorDomains,
        ];
    }
}
