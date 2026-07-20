<?php

namespace App\Enums;

enum ScoringType: string
{
    case Boolean = 'boolean';
    case Linear = 'linear';
    case InverseLinear = 'inverse_linear';
    case Range = 'range';
    case Threshold = 'threshold';
    case Ratio = 'ratio';
    case Lighthouse = 'lighthouse';
    case Manual = 'manual';
    case NotScored = 'not_scored';
}
