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
    /**
     * @param  bool  $crawlSiteEnabled  依頼BV-3: 親Analysis.crawl_siteの値。
     *                                  「巡回0件」が機能自体を使っていない
     *                                  (crawl_site=false、多数派)ことによる
     *                                  ものか、機能を使ったのにこのサイト
     *                                  だけ巡回が始まらなかったか(LINEヤフー
     *                                  の実例)を見分けるために必要。
     */
    public function summarize(WebsiteAnalysis $websiteAnalysis, bool $crawlSiteEnabled): array
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

        // 依頼BV-2: candidateCountがnull(依頼BV適用前の既存データ、または
        // finalizeCrawl()に未到達=巡回自体が始まっていない)の場合は
        // 「候補あり/成功0」の判定ができない ―― 区別できないものを異常
        // 扱いしない。
        $candidateCount = $websiteAnalysis->render_candidate_count;

        return [
            'has_crawl_data' => $hasCrawlData,
            'fetched_count' => $fetchedCount,
            'failed_count' => $failedCount,
            'pending_count' => $pendingCount,
            'rendered_count' => $renderedCount,
            'render_candidate_count' => $candidateCount,
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
            'warnings' => $this->warnings($hasCrawlData, $reason, $fetchedCount, $failedCount, $candidateCount, $renderedCount),
            // 依頼BV-3(この依頼の主目的): BU-3の3条件より一段重い、独立した
            // 警告。crawl_site=trueなのにこのサイトだけ巡回が1ページも
            // 行われなかった場合にのみ出す ―― crawl_site=false(機能自体を
            // 使っていない、多数派)では出さない(誤って大量に警告扱い
            // しないため)。
            'critical_warning' => ($crawlSiteEnabled && ! $hasCrawlData)
                ? ['key' => 'crawl_not_started', 'message' => (string) config('crawl_diagnostics.crawl_not_started_message', '')]
                : null,
        ];
    }

    /**
     * 依頼BU-3/BV-2: 「この数字を疑うべき」条件のうち満たすものだけを
     * 返す。閾値・一文はすべてconfigから出す(直書きしない、依頼者指定)。
     * 巡回自体を行っていない診断(hasCrawlData=false、crawl_site=falseや
     * 既存データ)は、比較対象の実績が無いため警告そのものを出さない
     * (fetchedCount=0が常にlow_fetched_page_countの閾値を下回ってしまい、
     * 巡回していないだけのサイトを誤って警告扱いしてしまうのを防ぐ ――
     * この場合はcritical_warning側で一段重く扱う)。
     *
     * @return list<array{key: string, message: string}>
     */
    private function warnings(bool $hasCrawlData, ?string $reason, int $fetchedCount, int $failedCount, ?int $candidateCount, int $renderedCount): array
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

        // 依頼BV-2: 候補はあった(N>0)のに1枚も成功しなかった(renderedCount
        // ===0)場合のみ警告する。候補0件(静的HTMLで足りていた、正常)や、
        // candidateCount===null(依頼BV適用前の既存データ)は対象外。
        if ($candidateCount !== null && $candidateCount > 0 && $renderedCount === 0) {
            $warnings[] = ['key' => 'rendering_failed', 'message' => $messages['rendering_failed'] ?? ''];
        }

        return $warnings;
    }
}
