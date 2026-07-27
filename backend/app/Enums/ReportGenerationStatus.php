<?php

namespace App\Enums;

enum ReportGenerationStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
