<?php

namespace App\Enums;

enum NotFoundPolicy: string
{
    case Zero = 'zero';
    case Exclude = 'exclude';
    case Partial = 'partial';
}
