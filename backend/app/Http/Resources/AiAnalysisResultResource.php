<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AI分析結果のレスポンス。Semrush同様、providerや生成日時・is_mockを必ず
 * 含め、Mockの結果を実データと見分けがつかない形で返さないようにする。
 *
 * @mixin \App\Models\AiAnalysisResult
 */
class AiAnalysisResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'analysis_id' => $this->analysis_id,
            'website_analysis_id' => $this->website_analysis_id,
            'provider' => $this->provider,
            'model' => $this->model,
            'status' => $this->status,
            'summary' => $this->summary,
            'strengths' => $this->strengths ?? [],
            'weaknesses' => $this->weaknesses ?? [],
            'priority_actions' => $this->priority_actions ?? [],
            'competitor_insights' => $this->competitor_insights ?? [],
            'cautions' => $this->cautions ?? [],
            'confidence' => $this->confidence !== null ? (float) $this->confidence : null,
            'is_mock' => (bool) $this->is_mock,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
