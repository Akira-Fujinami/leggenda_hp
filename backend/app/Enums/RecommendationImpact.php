<?php

namespace App\Enums;

enum RecommendationImpact: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function score(): float
    {
        return match ($this) {
            self::High => 1.0,
            self::Medium => 0.6,
            self::Low => 0.3,
        };
    }
}
