<?php

namespace App\Services\Scoring\Strategies;

use App\Models\MetricResult;

trait ExtractsNormalizedValue
{
    private function numericValue(MetricResult $result): ?float
    {
        $value = $result->normalized_value['value'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function boolValue(MetricResult $result): ?bool
    {
        $value = $result->normalized_value['value'] ?? null;

        return is_bool($value) ? $value : null;
    }
}
