<?php

namespace App\Enums;

enum RecommendationSource: string
{
    case Rule = 'rule';
    case ExternalApi = 'external_api';
    case Ai = 'ai';
    case Manual = 'manual';
}
