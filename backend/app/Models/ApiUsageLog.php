<?php

namespace App\Models;

use Database\Factories\ApiUsageLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'provider', 'operation', 'analysis_id', 'website_analysis_id', 'request_hash',
    'status', 'http_status', 'units_used', 'estimated_cost', 'duration_ms', 'error_code',
])]
class ApiUsageLog extends Model
{
    /** @use HasFactory<ApiUsageLogFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'units_used' => 'integer',
            'estimated_cost' => 'decimal:4',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
