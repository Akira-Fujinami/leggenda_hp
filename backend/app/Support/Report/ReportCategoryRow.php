<?php

namespace App\Support\Report;

readonly class ReportCategoryRow
{
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public float $score,
        public float $configuredMaxScore,
        public float $coverageRate,
        // null: 通常表示(score/configuredMaxScore)。
        // CategoryAvailabilityClassifier::NOT_MEASURED: 「計測対象外」。
        // CategoryAvailabilityClassifier::UNAVAILABLE: 「評価不可」。
        public ?string $availability,
    ) {}
}
