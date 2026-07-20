<?php

namespace App\Support\Comparison;

readonly class RankedSite
{
    public function __construct(
        public int $rank,
        public SiteScoreEntry $entry,
        public bool $lowDataWarning,
    ) {
    }
}
