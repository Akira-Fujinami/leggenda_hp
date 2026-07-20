<?php

namespace App\Services\Scoring;

class CoverageCalculator
{
    /**
     * 配点に対して実際に測定できた割合(0-100)。configuredMaxが0の場合は
     * 0除算を避けて0を返す。
     */
    public function rate(float $availableMax, float $configuredMax): float
    {
        if ($configuredMax <= 0) {
            return 0.0;
        }

        return round(max(0.0, min(1.0, $availableMax / $configuredMax)) * 100, 2);
    }
}
