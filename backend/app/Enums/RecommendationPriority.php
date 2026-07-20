<?php

namespace App\Enums;

enum RecommendationPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
