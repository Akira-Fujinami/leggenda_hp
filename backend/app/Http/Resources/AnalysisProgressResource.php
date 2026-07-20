<?php

namespace App\Http\Resources;

use App\Models\Analysis;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\JobStatusSummarizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * ポーリング用の軽量な進捗表示。実HTML/Lighthouse生データ/スクリーンショットは
 * 含めない。進捗(0-100)は必ずサーバー側(ProgressCalculator)で算出した値を返す
 * ―― フロントエンドが自前で推測しないようにするため。
 *
 * jobs/job_summaryのJob集計(総数・完了・失敗・実行中・待機中・スキップ・
 * 処理終了数)もあわせて返す ―― フロントエンドが「進捗100%なのにpartialなのは
 * なぜか」を成功数・失敗数の内訳から説明できるようにするため。
 *
 * @mixin Analysis
 */
class AnalysisProgressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summarizer = app(JobStatusSummarizer::class);

        /** @var Collection<int, WebsiteAnalysis> $websiteAnalyses */
        $websiteAnalyses = $this->relationLoaded('websiteAnalyses') ? $this->websiteAnalyses : collect();

        $allJobs = $websiteAnalyses->flatMap(
            fn ($wa) => $wa->relationLoaded('jobs') ? $wa->jobs : collect()
        );

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'progress' => $this->progress,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'jobs' => $summarizer->summarize($allJobs),
            'websites' => $this->whenLoaded('websiteAnalyses', fn () => $websiteAnalyses->map(fn ($wa) => [
                'website_analysis_id' => $wa->id,
                'website_id' => $wa->website_id,
                'website_name' => $wa->website?->name,
                'status' => $wa->status->value,
                'progress' => $wa->progress,
                'job_summary' => $summarizer->summarize($wa->relationLoaded('jobs') ? $wa->jobs : collect()),
                'jobs' => $wa->jobs->map(fn ($job) => [
                    'job_type' => $job->job_type->value,
                    'status' => $job->status->value,
                    'error_message' => $job->error_message,
                ])->values(),
            ])->values()),
        ];
    }
}
