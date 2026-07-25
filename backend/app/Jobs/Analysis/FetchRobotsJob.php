<?php

namespace App\Jobs\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Enums\PageType;
use App\Exceptions\Analysis\AnalysisException;
use App\Jobs\Analysis\Concerns\RecordsMetricResults;
use App\Jobs\Analysis\Concerns\WritesAnalysisStorage;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\Analysis\RobotsTxtParser;
use App\Services\Analysis\SafeHttpFetcher;

/**
 * robots.txtの取得と解析。robots.txtが存在しない(404)ことは技術的な失敗ではなく
 * 正当な状態なので、その場合もcompleted扱いとする(robots_fetchedメトリクスは満点)。
 * ネットワーク層の失敗(タイムアウト・SSRF拒否等)のみをエラーとして扱う。
 *
 * HTTPステータスの解釈:
 * - 200: 存在する(あり)。
 * - 404: 明確に存在しない(なし)。
 * - 403やそれ以外の4xx/5xx: アクセス拒否・サーバーエラー等で「存在するかどうか
 *   判定できない」状態のため、Success(あり/なし)ではなくUnavailableとして
 *   記録する(存在しない=falseと誤判定しない)。
 */
class FetchRobotsJob extends BaseWebsiteAnalysisJob
{
    use RecordsMetricResults, WritesAnalysisStorage;

    public $tries = 2;

    // SafeHttpFetcherのHTTP total_timeout(既定20秒)より長く保つこと。
    public $timeout = 30;

    public function jobType(): JobType
    {
        return JobType::FetchRobots;
    }

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        $website = $websiteAnalysis->website;
        $robotsUrl = rtrim($website->normalized_url, '/').'/robots.txt';

        /** @var SafeHttpFetcher $fetcher */
        $fetcher = app(SafeHttpFetcher::class);

        try {
            // robots.txtが存在しない(404等)場合はcontent-typeを問わず「無し」として
            // 扱いたいため、ここではcontent-type制限をかけない
            // (200のときだけ本文をtext/txtとしてパースする)。
            $result = $fetcher->fetch($robotsUrl);
        } catch (AnalysisException $e) {
            $this->recordMetric($this->websiteAnalysisId, 'robots_fetched', MetricResultStatus::Error, errorCode: $e->errorCode->value, errorMessage: $e->getMessage());

            throw $e;
        }

        $exists = $result->httpStatus === 200;
        $unknown = ! $exists && $result->httpStatus !== 404;
        $parsed = $exists ? app(RobotsTxtParser::class)->parse($result->body) : ['disallow' => [], 'allow' => [], 'sitemaps' => [], 'parse_error' => false];

        $htmlPath = null;
        if ($exists) {
            /** @var AnalysisStoragePaths $paths */
            $paths = app(AnalysisStoragePaths::class);
            $htmlPath = $paths->rawHtmlPath($this->analysisId, $this->websiteAnalysisId, 'robots.txt');

            try {
                $this->putToAnalysisStorage($htmlPath, $result->body);
            } catch (AnalysisException $e) {
                $this->recordMetric($this->websiteAnalysisId, 'robots_fetched', MetricResultStatus::Error, errorCode: $e->errorCode->value, errorMessage: $e->getMessage());

                throw $e;
            }
        }

        $page = AnalysisPage::query()->updateOrCreate(
            ['website_analysis_id' => $this->websiteAnalysisId, 'page_type' => PageType::Robots],
            [
                'url' => $result->requestedUrl,
                'final_url' => $result->finalUrl,
                'http_status' => $result->httpStatus,
                'content_type' => $result->contentType,
                'raw_html_path' => $htmlPath,
                'fetched_at' => now(),
            ],
        );

        $this->recordMetric(
            $this->websiteAnalysisId,
            'robots_fetched',
            $unknown ? MetricResultStatus::Unavailable : MetricResultStatus::Success,
            normalizedValue: $unknown ? null : $exists,
            rawValue: ['exists' => $exists, 'http_status' => $result->httpStatus] + $parsed,
            evidence: ['url' => $robotsUrl, 'parsed' => $parsed],
            errorCode: $unknown ? AnalysisErrorCode::HttpError->value : null,
            errorMessage: $unknown ? "robots.txtの取得でHTTP {$result->httpStatus} が返されました。" : null,
            analysisPageId: $page->id,
        );
    }
}
