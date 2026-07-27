<?php

namespace App\Support\Report;

readonly class ReportRecommendationRow
{
    public function __construct(
        public string $title,
        public string $description,
        public string $priorityLabel,
        public string $impactLabel,
        public string $effortLabel,
    ) {}
}
