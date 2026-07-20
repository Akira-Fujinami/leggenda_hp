<?php

namespace App\Support\Scoring;

readonly class ScoreThreshold
{
    public function __construct(
        public float $min,
        public float $max,
        public float $scoreRate,
    ) {
    }

    public function contains(float $value): bool
    {
        return $value >= $this->min && $value <= $this->max;
    }
}
