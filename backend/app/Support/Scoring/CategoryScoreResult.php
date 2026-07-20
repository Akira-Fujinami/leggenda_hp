<?php

namespace App\Support\Scoring;

readonly class CategoryScoreResult
{
    public function __construct(
        public string $key,
        public string $name,
        public float $score,
        public float $maxAvailableScore,
        public float $configuredMaxScore,
        public float $coverageRate,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'score' => round($this->score, 2),
            'max_available_score' => round($this->maxAvailableScore, 2),
            'configured_max_score' => round($this->configuredMaxScore, 2),
            'coverage_rate' => round($this->coverageRate, 2),
        ];
    }
}
