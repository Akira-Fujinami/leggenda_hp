<?php

namespace App\Models;

use Database\Factories\BrandWheelAnalysisResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BrandWheelAnalysisProviderの出力の永続化。Raw Prompt/レスポンス全文は
 * 保存しない(理由はマイグレーションのコメントを参照)。
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
#[Fillable([
    'analysis_id', 'website_analysis_id', 'provider', 'model', 'status', 'prompt_version',
    'axes', 'core_value_readable', 'core_value_evidence', 'quality_dimension_notes', 'cautions',
    'axis_state_counts', 'is_mock', 'input_hash', 'input_truncated', 'source_pages',
    'usage_input_tokens', 'usage_output_tokens', 'duration_ms', 'error_code', 'error_message', 'generated_at',
    'staff_notified_at', 'lead_notified_at',
])]
class BrandWheelAnalysisResult extends Model
{
    /** @use HasFactory<BrandWheelAnalysisResultFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'axes' => 'array',
            'core_value_readable' => 'boolean',
            'quality_dimension_notes' => 'array',
            'cautions' => 'array',
            'axis_state_counts' => 'array',
            'is_mock' => 'boolean',
            'input_truncated' => 'boolean',
            'source_pages' => 'array',
            'usage_input_tokens' => 'integer',
            'usage_output_tokens' => 'integer',
            'duration_ms' => 'integer',
            'generated_at' => 'datetime',
            'staff_notified_at' => 'datetime',
            'lead_notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Analysis, $this>
     */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    /**
     * @return BelongsTo<WebsiteAnalysis, $this>
     */
    public function websiteAnalysis(): BelongsTo
    {
        return $this->belongsTo(WebsiteAnalysis::class);
    }
}
