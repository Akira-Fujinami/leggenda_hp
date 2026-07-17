<?php

namespace App\Jobs\Analysis;

use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Enums\PageType;
use App\Exceptions\Analysis\AnalysisException;
use App\Jobs\Analysis\Concerns\RecordsMetricResults;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\Analysis\SitemapParser;
use App\Services\Analysis\SafeHttpFetcher;
use Illuminate\Support\Facades\Storage;

/**
 * sitemap.xmlの取得と解析。
 * 簡略化: robots.txtが宣言する実際のSitemap行は参照せず、まず
 * `{origin}/sitemap.xml` の慣習的なパスのみを確認する(Phase 2 MVP)。
 * 存在しない(404)ことは技術的失敗ではないため、その場合もcompletedとする。
 */
class FetchSitemapJob extends BaseWebsiteAnalysisJob
{
    use RecordsMetricResults;

    public $tries = 2;

    public $timeout = 15;

    public function jobType(): JobType
    {
        return JobType::FetchSitemap;
    }

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        $website = $websiteAnalysis->website;
        $sitemapUrl = rtrim($website->normalized_url, '/').'/sitemap.xml';

        /** @var SafeHttpFetcher $fetcher */
        $fetcher = app(SafeHttpFetcher::class);

        try {
            // sitemap.xmlが存在しない(404等)場合はcontent-typeを問わず「無し」として
            // 扱いたいため、ここではcontent-type制限をかけない
            // (200のときだけ本文をXMLとしてパースする)。
            $result = $fetcher->fetch($sitemapUrl);
        } catch (AnalysisException $e) {
            $this->recordMetric($this->websiteAnalysisId, 'sitemap_fetched', MetricResultStatus::Error, errorCode: $e->errorCode->value, errorMessage: $e->getMessage());

            throw $e;
        }

        $exists = $result->httpStatus === 200;
        $parsed = $exists
            ? app(SitemapParser::class)->parse($result->body)
            : ['kind' => null, 'url_count' => 0, 'sitemap_count' => 0, 'parse_error' => false, 'truncated' => false];

        $htmlPath = null;
        if ($exists) {
            /** @var AnalysisStoragePaths $paths */
            $paths = app(AnalysisStoragePaths::class);
            $htmlPath = $paths->rawHtmlPath($this->analysisId, $this->websiteAnalysisId, 'sitemap.xml');
            Storage::disk('analysis')->put($htmlPath, $result->body);
        }

        $page = AnalysisPage::query()->updateOrCreate(
            ['website_analysis_id' => $this->websiteAnalysisId, 'page_type' => PageType::Sitemap],
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
            'sitemap_fetched',
            MetricResultStatus::Success,
            achievedRatio: 1.0,
            rawValue: ['exists' => $exists, 'http_status' => $result->httpStatus] + $parsed,
            evidence: ['url' => $sitemapUrl, 'parsed' => $parsed],
            analysisPageId: $page->id,
        );
    }
}
