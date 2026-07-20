<?php

namespace App\Http\Resources;

use App\Enums\AnalysisJobStatus;
use App\Enums\PageType;
use App\Models\CategoryDefinition;
use App\Services\Scoring\OverallScoreCalculator;
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
        $activeCategories = CategoryDefinition::query()->where('is_active', true)->orderBy('display_order')->get();
        $calculator = app(OverallScoreCalculator::class);

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'progress' => $this->progress,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'websites' => $this->websiteAnalyses->map(function ($wa) use ($activeCategories, $calculator) {
                $homepage = $wa->pages->firstWhere('page_type', PageType::Homepage);
                $score = $calculator->calculate($activeCategories, $wa->metricResults);

                return [
                    'website_analysis_id' => $wa->id,
                    'website_id' => $wa->website_id,
                    'website_name' => $wa->website?->name,
                    'url' => $wa->website?->normalized_url,
                    'is_primary' => (bool) $wa->website?->is_primary,
                    'status' => $wa->status->value,
                    'http_status' => $wa->http_status,
                    'final_url' => $wa->final_url,
                    'score' => $score->toArray(),
                    'seo' => $homepage === null ? null : [
                        'title' => $homepage->title,
                        'meta_description' => $homepage->meta_description,
                        'h1_count' => $homepage->h1_count,
                        'word_count' => $homepage->word_count,
                    ],
                    'lighthouse' => $this->lighthouseSummary($wa),
                    'technology' => $this->technologySummary($wa),
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
        $scores = collect(['performance', 'accessibility', 'best_practices'])->mapWithKeys(function (string $name) use ($websiteAnalysis) {
            $metric = $websiteAnalysis->metricResults->first(
                fn ($r) => $r->metricDefinition?->key === "lighthouse_{$name}"
            );

            return [$name => $metric?->normalized_value['value'] ?? null];
        });

        $metrics = collect(['fcp', 'lcp', 'cls', 'speed_index', 'tbt'])->mapWithKeys(function (string $key) use ($websiteAnalysis) {
            $metric = $websiteAnalysis->metricResults->first(
                fn ($r) => $r->metricDefinition?->key === $key
            );

            return [$key => $metric?->normalized_value['value'] ?? null];
        });

        return [
            'scores' => $scores->all(),
            'metrics' => $metrics->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function technologySummary($websiteAnalysis): array
    {
        $keys = ['cms_detected', 'ga_detected', 'gtm_detected', 'clarity_detected', 'meta_pixel_detected', 'recaptcha_detected', 'cdn_detected'];

        $detected = [];
        foreach ($keys as $key) {
            $metric = $websiteAnalysis->metricResults->first(fn ($r) => $r->metricDefinition?->key === $key);

            if ($metric === null) {
                continue;
            }

            $detected[$key] = $metric->normalized_value['value'] ?? null;
        }

        return $detected;
    }
}
