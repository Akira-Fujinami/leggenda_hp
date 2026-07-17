<?php

namespace App\Enums;

enum MetricResultStatus: string
{
    case Success = 'success';
    case NotFound = 'not_found';
    case NotApplicable = 'not_applicable';
    case Unavailable = 'unavailable';
    case Error = 'error';
}
