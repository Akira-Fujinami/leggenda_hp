<?php

namespace App\Services\Semrush\Data;

readonly class SeoBacklinkMetrics
{
    public function __construct(
        public ?int $backlinksCount = null,
        public ?int $referringDomainsCount = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'backlinks_count' => $this->backlinksCount,
            'referring_domains_count' => $this->referringDomainsCount,
        ];
    }
}
