<?php

namespace App\Models;

use Database\Factories\AiAnalysisResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AiAnalysisProviderの出力の永続化。Raw Prompt全文は保存しない。
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
#[Fillable([
    'analysis_id', 'website_analysis_id', 'provider', 'model', 'status', 'summary',
    'strengths', 'weaknesses', 'priority_actions', 'competitor_insights', 'cautions',
    'confidence', 'is_mock', 'input_hash', 'usage_input_tokens', 'usage_output_tokens',
    'duration_ms', 'error_code', 'error_message', 'generated_at',
])]
class AiAnalysisResult extends Model
{
    /** @use HasFactory<AiAnalysisResultFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'strengths' => 'array',
            'weaknesses' => 'array',
            'priority_actions' => 'array',
            'competitor_insights' => 'array',
            'cautions' => 'array',
            'confidence' => 'decimal:2',
            'is_mock' => 'boolean',
            'usage_input_tokens' => 'integer',
            'usage_output_tokens' => 'integer',
            'duration_ms' => 'integer',
            'generated_at' => 'datetime',
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
