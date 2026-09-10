<?php

namespace App\Services\Admin;

use App\Models\AnalysisCrawledPage;
use App\Models\WebsiteAnalysis;

/**
 * 依頼BU(2026-09-11): 診断詳細画面の「サイトごとの巡回実績」向けに、
 * analysis_crawled_pages(依頼C以降の巡回フロンティア)を集計する。
 * 巡回のロジック自体(CrawlWebsiteJob/CrawlWebsitePageJob/
 * RenderCrawledPageJob)には一切手を入れず、既に保存されている実績を
 * 読むだけ(依頼者指定の範囲)。
 *
 * crawl_site=falseの診断や、巡回未実施の診断ではanalysis_crawled_pagesに
 * 行が無い ―― その場合はhasCrawlData=falseとして返し、呼び出し側
 * (blade)が「巡回していません」の表示に倒せるようにする。
 */
class CrawlDiagnosticsService
{
    public function summarize(WebsiteAnalysis $websiteAnalysis): array
    {
        $pages = AnalysisCrawledPage::query()->where('website_analysis_id', $websiteAnalysis->id);

        $counts = (clone $pages)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        $fetchedCount = (int) ($counts[AnalysisCrawledPage::STATUS_FETCHED] ?? 0);
        $failedCount = (int) ($counts[AnalysisCrawledPage::STATUS_FAILED] ?? 0);
        $pendingCount = (int) ($counts[AnalysisCrawledPage::STATUS_PENDING] ?? 0);

        $hasCrawlData = ((int) (clone $pages)->count()) > 0;

        $renderedCount = (int) (clone $pages)->whereNotNull('rendered_html_path')->count();

        $reason = $websiteAnalysis->crawl_finished_reason;
        $reasonLabels = (array) config('crawl_diagnostics.finished_reason_labels', []);
        $reasonLabel = $reason !== null && array_key_exists($reason, $reasonLabels)
            ? $reasonLabels[$reason]
            : (string) config('crawl_diagnostics.unknown_finished_reason_label', '不明');

        $durationSeconds = null;
        if ($websiteAnalysis->crawl_finished_at !== null) {
            $startedAt = (clone $pages)->min('created_at');
            if ($startedAt !== null) {
                $durationSeconds = $websiteAnalysis->crawl_finished_at->diffInSeconds($startedAt, absolute: true);
            }
        }

        $failedUrlLimit = (int) config('crawl_diagnostics.failed_url_display_limit', 10);
        $failedUrlsQuery = (clone $pages)
            ->where('status', AnalysisCrawledPage::STATUS_FAILED)
            ->orderBy('id');
        $failedUrls = (clone $failedUrlsQuery)
            ->limit($failedUrlLimit)
            ->get(['url', 'http_status'])
            ->map(fn (AnalysisCrawledPage $page) => [
                'url' => $page->url,
                'http_status' => $page->http_status,
            ])
            ->all();
        $failedUrlsOverflowCount = max(0, $failedCount - count($failedUrls));

        return [
            'has_crawl_data' => $hasCrawlData,
            'fetched_count' => $fetchedCount,
            'failed_count' => $failedCount,
            'pending_count' => $pendingCount,
            'rendered_count' => $renderedCount,
            'excluded_counts' => [
                'by_pattern' => (int) ($counts[AnalysisCrawledPage::STATUS_EXCLUDED_BY_PATTERN] ?? 0),
                'by_robots' => (int) ($counts[AnalysisCrawledPage::STATUS_EXCLUDED_BY_ROBOTS] ?? 0),
                'by_scope' => (int) ($counts[AnalysisCrawledPage::STATUS_EXCLUDED_BY_SCOPE] ?? 0),
                'by_track' => (int) ($counts[AnalysisCrawledPage::STATUS_EXCLUDED_BY_TRACK] ?? 0),
            ],
            'finished_reason' => $reason,
            'finished_reason_label' => $reasonLabel,
            'finished_at' => $websiteAnalysis->crawl_finished_at,
            'duration_seconds' => $durationSeconds,
            'failed_urls' => $failedUrls,
            'failed_urls_overflow_count' => $failedUrlsOverflowCount,
            'warnings' => $this->warnings($hasCrawlData, $reason, $fetchedCount, $failedCount),
        ];
    }

    /**
     * 依頼BU-3(この依頼の主目的): 「この数字を疑うべき」3条件のうち
     * 満たすものだけを返す。閾値・一文はすべてconfigから出す(直書きしない、
     * 依頼者指定)。巡回自体を行っていない診断(hasCrawlData=false、
     * crawl_site=falseや既存データ)は、比較対象の実績が無いため
     * 警告そのものを出さない(fetchedCount=0が常にlow_fetched_page_countの
     * 閾値を下回ってしまい、巡回していないだけのサイトを誤って
     * 警告扱いしてしまうのを防ぐ)。
     *
     * @return list<array{key: string, message: string}>
     */
    private function warnings(bool $hasCrawlData, ?string $reason, int $fetchedCount, int $failedCount): array
    {
        if (! $hasCrawlData) {
            return [];
        }

        $warnings = [];
        $messages = (array) config('crawl_diagnostics.warning_messages', []);

        $lowFetchedThreshold = (int) config('crawl_diagnostics.low_fetched_page_count_threshold', 10);
        if ($fetchedCount < $lowFetchedThreshold) {
            $warnings[] = ['key' => 'low_fetched_page_count', 'message' => $messages['low_fetched_page_count'] ?? ''];
        }

        if ($reason === 'total_timeout') {
            $warnings[] = ['key' => 'total_timeout', 'message' => $messages['total_timeout'] ?? ''];
        }

        $attempted = $fetchedCount + $failedCount;
        $highFailureRateThreshold = (float) config('crawl_diagnostics.high_failure_rate_threshold', 0.3);
        if ($attempted > 0 && ($failedCount / $attempted) >= $highFailureRateThreshold) {
            $warnings[] = ['key' => 'high_failure_rate', 'message' => $messages['high_failure_rate'] ?? ''];
        }

        return $warnings;
    }
}
