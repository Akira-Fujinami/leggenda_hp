<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Recommendation */
class RecommendationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'website_analysis_id' => $this->website_analysis_id,
            'website_name' => $this->whenLoaded('websiteAnalysis', fn () => $this->websiteAnalysis->website?->name),
            'category_key' => $this->category_key,
            'title' => $this->title,
            'description' => $this->description,
            'evidence' => $this->evidence,
            'current_value' => $this->current_value,
            'recommended_value' => $this->recommended_value,
            'priority' => $this->priority->value,
            'impact' => $this->impact->value,
            'effort' => $this->effort->value,
            'confidence' => (float) $this->confidence,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'sort_score' => (float) $this->sort_score,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
