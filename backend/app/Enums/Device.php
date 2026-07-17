<?php

namespace App\Enums;

enum Device: string
{
    case Desktop = 'desktop';
    case Mobile = 'mobile';

    public function width(): int
    {
        return match ($this) {
            self::Desktop => 1440,
            self::Mobile => 390,
        };
    }

    public function height(): int
    {
        return match ($this) {
            self::Desktop => 1000,
            self::Mobile => 844,
        };
    }
}
