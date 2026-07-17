<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ポーリング用の軽量な進捗表示。実HTML/Lighthouse生データ/スクリーンショットは
 * 含めない。進捗(0-100)は必ずサーバー側(ProgressCalculator)で算出した値を返す
 * ―― フロントエンドが自前で推測しないようにするため。
 *
 * @mixin \App\Models\Analysis
 */
class AnalysisProgressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'progress' => $this->progress,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'websites' => $this->whenLoaded('websiteAnalyses', fn () => $this->websiteAnalyses->map(fn ($wa) => [
                'website_analysis_id' => $wa->id,
                'website_id' => $wa->website_id,
                'website_name' => $wa->website?->name,
                'status' => $wa->status->value,
                'progress' => $wa->progress,
                'jobs' => $wa->jobs->map(fn ($job) => [
                    'job_type' => $job->job_type->value,
                    'status' => $job->status->value,
                ])->values(),
            ])->values()),
        ];
    }
}
