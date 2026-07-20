<?php

namespace App\Enums;

enum RecommendationEffort: string
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';

    /**
     * 工数が小さいほど優先度計算上のスコアが高くなるようにする係数。
     */
    public function easeScore(): float
    {
        return match ($this) {
            self::Small => 1.0,
            self::Medium => 0.6,
            self::Large => 0.3,
        };
    }
}
