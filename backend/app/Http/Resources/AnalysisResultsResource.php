<?php

namespace App\Http\Resources;

use App\Enums\AnalysisJobStatus;
use App\Enums\PageType;
use App\Models\MetricDefinition;
use App\Services\Analysis\ScoreCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 分析結果画面向けの詳細レスポンス。
 * 生HTML・Lighthouseの生JSON・スクリーンショットのbase64は一切含めない
 * (正規化済みデータとストレージURLのみ)。
 *
 * @mixin \App\Models\Analysis
 */
class AnalysisResultsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $activeDefinitions = MetricDefinition::query()->where('is_active', true)->get();
        $calculator = app(ScoreCalculator::class);

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'progress' => $this->progress,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'websites' => $this->websiteAnalyses->map(function ($wa) use ($activeDefinitions, $calculator) {
                $homepage = $wa->pages->firstWhere('page_type', PageType::Homepage);
                $score = $calculator->calculate($wa->metricResults, $activeDefinitions);
                $technologyResult = $wa->metricResults->first(
                    fn ($r) => $r->metricDefinition?->key === 'technology_detected'
                );

                return [
                    'website_analysis_id' => $wa->id,
                    'website_id' => $wa->website_id,
                    'website_name' => $wa->website?->name,
                    'url' => $wa->website?->normalized_url,
                    'status' => $wa->status->value,
                    'http_status' => $wa->http_status,
                    'final_url' => $wa->final_url,
                    'score' => $score,
                    'seo' => $homepage === null ? null : [
                        'title' => $homepage->title,
                        'meta_description' => $homepage->meta_description,
                        'h1_count' => $homepage->h1_count,
                        'word_count' => $homepage->word_count,
                    ],
                    'lighthouse' => $this->lighthouseSummary($wa),
                    'technology' => $technologyResult?->raw_value['technologies'] ?? [],
                    'screenshots' => $wa->screenshots->map(fn ($s) => [
                        'device' => $s->device->value,
                        'url' => route('analyses.screenshot', ['websiteAnalysis' => $wa->id, 'device' => $s->device->value]),
                        'width' => $s->width,
                        'height' => $s->height,
                    ])->values(),
                    'errors' => $wa->jobs
                        ->filter(fn ($job) => $job->status === AnalysisJobStatus::Failed)
                        ->map(fn ($job) => [
                            'job_type' => $job->job_type->value,
                            'error_code' => $job->error_code,
                            'error_message' => $job->error_message,
                        ])->values(),
                ];
            })->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lighthouseSummary($websiteAnalysis): array
    {
        $result = collect(['performance', 'seo', 'accessibility'])->mapWithKeys(function (string $name) use ($websiteAnalysis) {
            $metric = $websiteAnalysis->metricResults->first(
                fn ($r) => $r->metricDefinition?->key === "lighthouse_{$name}"
            );

            return [$name => $metric?->raw_value['scores'][$name] ?? $metric?->raw_value['score'] ?? null];
        });

        $metricsSource = $websiteAnalysis->metricResults->first(
            fn ($r) => $r->metricDefinition?->key === 'lighthouse_performance'
        );

        return [
            'scores' => $result->all(),
            'metrics' => $metricsSource?->raw_value['metrics'] ?? null,
        ];
    }
}
