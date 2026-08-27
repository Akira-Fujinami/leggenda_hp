<?php

namespace App\Models;

use Database\Factories\BrandWheelImprovementSuggestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 改善提案(page6)のAI生成テキストの永続化。Analysis単位(自社×競合の比較
 * 単位)で1件、BrandWheelImprovementSuggestionDispatcherが生成する
 * (詳細はマイグレーションのコメントを参照)。
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
#[Fillable([
    'analysis_id', 'status', 'provider', 'model', 'prompt_version',
    'one_point', 'recommendation', 'focus_sub_element_keys', 'is_mock', 'input_hash',
    'reason', 'recommended_contents', 'mid_term_action', 'quick_win',
    'implementation_difficulty', 'candidate_impact', 'gap_closing', 'differentiation_opportunities',
    'focus_items_reason', 'focus_items_reason_sub_names',
    'usage_input_tokens', 'usage_output_tokens', 'duration_ms', 'error_code', 'error_message', 'generated_at',
])]
class BrandWheelImprovementSuggestion extends Model
{
    /** @use HasFactory<BrandWheelImprovementSuggestionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'focus_sub_element_keys' => 'array',
            'recommended_contents' => 'array',
            'gap_closing' => 'array',
            'differentiation_opportunities' => 'array',
            'focus_items_reason_sub_names' => 'array',
            'quick_win' => 'boolean',
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
}
