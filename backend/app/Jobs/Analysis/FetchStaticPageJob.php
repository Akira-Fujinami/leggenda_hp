<?php

namespace App\Jobs\Analysis;

use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Enums\PageType;
use App\Jobs\Analysis\Concerns\RecordsMetricResults;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\Analysis\SafeHttpFetcher;
use Illuminate\Support\Facades\Storage;

/**
 * トップページの静的HTML(JS実行前)を取得して保存する。
 * AnalyzeHtmlSeoJobはこのジョブが保存したHTMLに依存するため、
 * 成功・失敗どちらの場合も必ず(finally句で)AnalyzeHtmlSeoJobを起動する
 * ―― でなければ、事前登録されたAnalyzeHtmlSeoJobのAnalysisJob行が
 * 永久にpendingのままとなり、WebsiteAnalysisの完了判定が止まってしまう。
 */
class FetchStaticPageJob extends BaseWebsiteAnalysisJob
{
    use RecordsMetricResults;

    public $tries = 3;

    public $timeout = 30;

    public function jobType(): JobType
    {
        return JobType::FetchStaticPage;
    }

    private const HTTP_METRIC_KEYS = ['https', 'http_status_ok', 'redirect_count_low'];

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        try {
            $website = $websiteAnalysis->website;

            /** @var SafeHttpFetcher $fetcher */
            $fetcher = app(SafeHttpFetcher::class);

            try {
                $result = $fetcher->fetch($website->normalized_url, ['text/html', 'application/xhtml+xml']);
            } catch (\App\Exceptions\Analysis\AnalysisException $e) {
                $this->recordAllHttpMetricsUnavailable();

                throw $e;
            }

            /** @var AnalysisStoragePaths $paths */
            $paths = app(AnalysisStoragePaths::class);
            $htmlPath = $paths->rawHtmlPath($this->analysisId, $this->websiteAnalysisId, 'homepage.html');
            Storage::disk('analysis')->put($htmlPath, $result->body);

            AnalysisPage::query()->updateOrCreate(
                ['website_analysis_id' => $this->websiteAnalysisId, 'page_type' => PageType::Homepage],
                [
                    'url' => $result->requestedUrl,
                    'final_url' => $result->finalUrl,
                    'http_status' => $result->httpStatus,
                    'content_type' => $result->contentType,
                    'raw_html_path' => $htmlPath,
                    'fetched_at' => now(),
                ],
            );

            $websiteAnalysis->update([
                'http_status' => $result->httpStatus,
                'final_url' => $result->finalUrl,
                'response_time_ms' => $result->durationMs,
                'started_at' => $websiteAnalysis->started_at ?? now(),
            ]);

            $isHttps = parse_url($result->finalUrl, PHP_URL_SCHEME) === 'https';
            $this->recordMetric($this->websiteAnalysisId, 'https', MetricResultStatus::Success, normalizedValue: $isHttps, rawValue: ['final_url' => $result->finalUrl]);

            $statusOk = $result->httpStatus >= 200 && $result->httpStatus < 300;
            $this->recordMetric($this->websiteAnalysisId, 'http_status_ok', MetricResultStatus::Success, normalizedValue: $statusOk, rawValue: ['http_status' => $result->httpStatus]);

            $this->recordMetric($this->websiteAnalysisId, 'redirect_count_low', MetricResultStatus::Success, normalizedValue: $result->redirectCount, rawValue: ['redirect_count' => $result->redirectCount]);
        } finally {
            $pipeline->dispatchHtmlSeoAnalysis($this->analysisId, $this->websiteAnalysisId);
        }
    }

    private function recordAllHttpMetricsUnavailable(): void
    {
        foreach (self::HTTP_METRIC_KEYS as $key) {
            $this->recordMetric($this->websiteAnalysisId, $key, MetricResultStatus::Unavailable);
        }
    }
}
