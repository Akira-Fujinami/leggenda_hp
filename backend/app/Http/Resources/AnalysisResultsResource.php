<?php

namespace App\Http\Resources;

use App\Enums\AnalysisJobStatus;
use App\Enums\PageType;
use App\Models\CategoryDefinition;
use App\Services\Scoring\MetricScorer;
use App\Services\Scoring\OverallScoreCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 分析結果画面向けの詳細レスポンス。
 * 生HTML・Lighthouseの生JSON・スクリーンショットのbase64は一切含めない
 * (正規化済みデータとストレージURLのみ)。MetricResult.raw_valueは
 * HtmlSeoAnalyzer等が抽出済みの小さな構造化データ(件数・真偽値・短い文字列)
 * のみであり、ページ全文やAPI生レスポンスではないため含めてよい。
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
                    'metrics' => $this->metricList($wa),
                    'recommendations' => $wa->recommendations
                        ->sortByDesc('sort_score')
                        ->map(fn ($r) => [
                            'id' => $r->id,
                            'category_key' => $r->category_key,
                            'title' => $r->title,
                            'description' => $r->description,
                            'evidence' => $r->evidence,
                            'current_value' => $r->current_value,
                            'recommended_value' => $r->recommended_value,
                            'priority' => $r->priority->value,
                            'impact' => $r->impact->value,
                            'effort' => $r->effort->value,
                            'confidence' => (float) $r->confidence,
                            'status' => $r->status->value,
                            'source' => $r->source->value,
                            'sort_score' => (float) $r->sort_score,
                        ])->values(),
                ];
            })->values(),
        ];
    }

    /**
     * カテゴリ別評価カード・SEO/コンテンツ/集客/表示速度/技術/外部SEOの各詳細
     * セクションが個別に組み立てられるよう、有効なMetricDefinitionに紐づく
     * 全MetricResultを構造化して返す。
     *
     * @return list<array<string, mixed>>
     */
    private function metricList($websiteAnalysis): array
    {
        $scorer = app(MetricScorer::class);

        return $websiteAnalysis->metricResults
            ->filter(fn ($r) => $r->metricDefinition !== null && $r->metricDefinition->is_active)
            ->map(function ($r) use ($scorer) {
                $definition = $r->metricDefinition;
                $outcome = $scorer->score($definition, $r);

                return [
                    'key' => $definition->key,
                    'name' => $definition->name,
                    'category_key' => $definition->category_key,
                    'unit' => $definition->unit,
                    'scoring_type' => $definition->scoring_type,
                    'status' => $r->status->value,
                    'value' => $r->normalized_value['value'] ?? null,
                    'raw_value' => $r->raw_value,
                    'min_value' => $definition->minimum_value !== null ? (float) $definition->minimum_value : null,
                    'target_value' => $definition->target_value !== null ? (float) $definition->target_value : null,
                    'max_value' => $definition->maximum_value !== null ? (float) $definition->maximum_value : null,
                    'higher_is_better' => (bool) $definition->higher_is_better,
                    'confidence' => $r->confidence !== null ? (float) $r->confidence : null,
                    'source_type' => $definition->source_type,
                    'measured_at' => $r->measured_at?->toIso8601String(),
                    'error_code' => $r->error_code,
                    'error_message' => $r->error_message,
                    // counts_toward_score=falseの項目(unavailable/error/not_applicable/
                    // not_scored等)はscore/max_scoreをnullのまま返す(0点にしない)。
                    'counts_toward_score' => $outcome->countsTowardScore,
                    'score' => $outcome->score,
                    'max_score' => $outcome->maxScore,
                ];
            })
            ->values()
            ->all();
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
