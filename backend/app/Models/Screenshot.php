<?php

namespace App\Models;

use App\Enums\Device;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['website_analysis_id', 'device', 'storage_path', 'width', 'height', 'file_size', 'mime_type', 'captured_at'])]
class Screenshot extends Model
{
    protected function casts(): array
    {
        return [
            'device' => Device::class,
            'width' => 'integer',
            'height' => 'integer',
            'file_size' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebsiteAnalysis, $this>
     */
    public function websiteAnalysis(): BelongsTo
    {
        return $this->belongsTo(WebsiteAnalysis::class);
    }
}
