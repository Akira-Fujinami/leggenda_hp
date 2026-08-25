<?php

namespace App\Jobs\Analysis;

use App\Exceptions\Analysis\AnalysisException;
use App\Jobs\Analysis\Concerns\WritesAnalysisStorage;
use App\Models\AnalysisCrawledPage;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\Analysis\CrawlLinkExtractor;
use App\Services\Analysis\CrawlPolicyResolver;
use App\Services\Analysis\HtmlSeoAnalyzer;
use App\Services\Analysis\PageHtmlResolver;
use App\Services\Analysis\RobotsTxtParser;
use App\Services\Analysis\SafeHttpFetcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * サイト全ページ巡回(依頼D-1)。1ジョブ=1ページ(フロンティアから`pending`を
 * 1件取り出して処理する)。取得後、次のCrawlWebsitePageJobを
 * `->delay(crawl_request_interval_seconds)`でdispatchして終了する ――
 * `sleep()`でワーカーを占有しない(依頼者指摘: sleep中もワーカーを1本
 * 占有し続けるため)。
 *
 * 上限(ページ数・総経過時間・総容量)は各ジョブの開始時にDBから毎回
 * 再計算して判定する ―― インメモリの状態を持たない独立ジョブの連鎖のため、
 * 前ジョブの実行結果を直接引き継げない。経過時間は
 * MIN(analysis_crawled_pages.created_at)(最初にseedされた行の作成時刻)を
 * 起点とする。
 */
class CrawlWebsitePageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesAnalysisStorage;

    public $tries = 1;

    public $timeout = 60;

    public function __construct(
        public readonly int $analysisId,
        public readonly int $websiteAnalysisId,
    ) {}

    public function handle(
        AnalysisPipeline $pipeline,
        SafeHttpFetcher $fetcher,
        CrawlLinkExtractor $linkExtractor,
        RobotsTxtParser $robotsTxtParser,
        CrawlPolicyResolver $policyResolver,
        AnalysisStoragePaths $paths,
        HtmlSeoAnalyzer $htmlSeoAnalyzer,
        PageHtmlResolver $htmlResolver,
    ): void {
        $maxPages = (int) config('brand_wheel.crawl_max_pages', 50);
        $fetchedCount = $this->pages()->where('status', AnalysisCrawledPage::STATUS_FETCHED)->count();
        if ($fetchedCount >= $maxPages) {
            $this->finalizeCrawl($pipeline, $htmlSeoAnalyzer, 'max_pages');

            return;
        }

        $startedAt = $this->pages()->min('created_at');
        $totalTimeoutSeconds = (int) config('brand_wheel.crawl_total_timeout_seconds', 900);
        if ($startedAt !== null && now()->diffInSeconds($startedAt, absolute: true) >= $totalTimeoutSeconds) {
            $this->finalizeCrawl($pipeline, $htmlSeoAnalyzer, 'total_timeout');

            return;
        }

        $storageUsed = (int) $this->pages()->sum('content_length');
        $storageBudget = (int) config('brand_wheel.crawl_max_total_storage_bytes', 52428800);
        if ($storageUsed >= $storageBudget) {
            $this->finalizeCrawl($pipeline, $htmlSeoAnalyzer, 'max_storage');

            return;
        }

        $next = $this->pages()
            ->where('status', AnalysisCrawledPage::STATUS_PENDING)
            ->orderBy('depth')
            ->orderBy('id')
            ->first();

        if ($next === null) {
            $this->finalizeCrawl($pipeline, $htmlSeoAnalyzer, 'exhausted');

            return;
        }

        $maxDepth = (int) config('brand_wheel.crawl_max_depth', 3);
        if ($next->depth > $maxDepth) {
            // 通常は起きない(enqueue側でdepth<maxDepthのときのみ次の深さを
            // 追加するため)。設定変更等への保険として、除外扱いにして
            // 次へ進む(HTTPを発行していないため間隔を空けずに続行)。
            $next->update(['status' => AnalysisCrawledPage::STATUS_EXCLUDED_BY_PATTERN]);
            $this->dispatchNext(false);

            return;
        }

        $path = (string) (parse_url($next->url, PHP_URL_PATH) ?? '/');
        $excludedPatterns = (array) config('brand_wheel.crawl_excluded_path_patterns', []);
        if ($this->matchesAnyPattern($path, $excludedPatterns) || $this->matchesAnyPattern($next->url, $excludedPatterns)) {
            $next->update(['status' => AnalysisCrawledPage::STATUS_EXCLUDED_BY_PATTERN]);
            $this->dispatchNext(false);

            return;
        }

        $robotsDecision = $policyResolver->resolveRobotsPolicy($this->websiteAnalysisId, $robotsTxtParser);
        if ($robotsDecision === null) {
            // seedジョブの時点では取得できていたrobots.txtが、その後読めなく
            // なった(ストレージ障害等)。安全側でクロール自体を打ち切る。
            $this->finalizeCrawl($pipeline, $htmlSeoAnalyzer, 'robots_became_unavailable');

            return;
        }
        if (! $robotsTxtParser->isPathAllowed($robotsDecision, $path)) {
            $next->update(['status' => AnalysisCrawledPage::STATUS_EXCLUDED_BY_ROBOTS]);
            $this->dispatchNext(false);

            return;
        }

        try {
            $result = $fetcher->fetch($next->url, ['text/html', 'application/xhtml+xml']);
        } catch (AnalysisException) {
            $next->update(['status' => AnalysisCrawledPage::STATUS_FAILED]);
            $this->dispatchNext(true);

            return;
        }

        if ($result->httpStatus < 200 || $result->httpStatus >= 300) {
            $next->update([
                'status' => AnalysisCrawledPage::STATUS_FAILED,
                'final_url' => $result->finalUrl,
                'http_status' => $result->httpStatus,
                'content_type' => $result->contentType,
            ]);
            $this->dispatchNext(true);

            return;
        }

        $allowedHosts = $policyResolver->allowedHosts($this->websiteAnalysisId);
        $finalHost = strtolower((string) parse_url($result->finalUrl, PHP_URL_HOST));
        if (! in_array($finalHost, $allowedHosts, true)) {
            $next->update([
                'status' => AnalysisCrawledPage::STATUS_EXCLUDED_BY_SCOPE,
                'final_url' => $result->finalUrl,
                'http_status' => $result->httpStatus,
            ]);
            $this->dispatchNext(true);

            return;
        }

        $htmlPath = $paths->rawHtmlPath($this->analysisId, $this->websiteAnalysisId, "crawl/{$next->url_hash}.html");
        $this->putToAnalysisStorage($htmlPath, $result->body);

        $next->update([
            'final_url' => $result->finalUrl,
            'http_status' => $result->httpStatus,
            'content_type' => $result->contentType,
            'content_length' => strlen($result->body),
            'raw_html_path' => $htmlPath,
            'status' => AnalysisCrawledPage::STATUS_FETCHED,
            'fetched_at' => now(),
        ]);

        if ($next->depth < $maxDepth) {
            foreach ($linkExtractor->extractAbsoluteLinks($result->body, $result->finalUrl) as $link) {
                $this->enqueue($link, $next->depth + 1, $allowedHosts);
            }
        }

        $this->dispatchNext(true);
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);
        Log::error('CrawlWebsitePageJob failed unexpectedly, finalizing the crawl', [
            'analysis_id' => $this->analysisId,
            'website_analysis_id' => $this->websiteAnalysisId,
            'exception' => $exception->getMessage(),
        ]);
        $this->finalizeCrawl(app(AnalysisPipeline::class), app(HtmlSeoAnalyzer::class), 'failed_exception');
    }

    private function pages(): \Illuminate\Database\Eloquent\Builder
    {
        return AnalysisCrawledPage::query()->where('website_analysis_id', $this->websiteAnalysisId);
    }

    private function dispatchNext(bool $withDelay): void
    {
        $pendingDispatch = self::dispatch($this->analysisId, $this->websiteAnalysisId)->onQueue('analysis');

        if ($withDelay) {
            $intervalSeconds = (float) config('brand_wheel.crawl_request_interval_seconds', 1.0);
            $pendingDispatch->delay(now()->addMilliseconds((int) round($intervalSeconds * 1000)));
        }

        $this->reportCrawlProgress();
    }

    /**
     * 依頼M-1: フロンティア(analysis_crawled_pages)のうち処理済み(取得成功/
     * 失敗/各種除外)の割合を、CrawlWebsiteのAnalysisJob.progressへ反映する。
     * サイトの規模に関わらず0→1へ収束する自己正規化した指標であり
     * (crawl_max_pagesに達する前にフロンティアが自然に枯渇する小規模サイトでも
     * 100%手前で止まったままにならない)、finalizeCrawl()が呼ばれる
     * (=処理済みがフロンティア全体に達する、または上限到達)瞬間に自然と
     * 100%近くへ収束する。
     */
    private function reportCrawlProgress(): void
    {
        $processed = $this->pages()->whereIn('status', [
            AnalysisCrawledPage::STATUS_FETCHED,
            AnalysisCrawledPage::STATUS_FAILED,
            AnalysisCrawledPage::STATUS_EXCLUDED_BY_PATTERN,
            AnalysisCrawledPage::STATUS_EXCLUDED_BY_ROBOTS,
            AnalysisCrawledPage::STATUS_EXCLUDED_BY_SCOPE,
        ])->count();
        $pending = $this->pages()->where('status', AnalysisCrawledPage::STATUS_PENDING)->count();

        $progress = (int) round(100 * $processed / max(1, $processed + $pending));

        app(AnalysisPipeline::class)->updateCrawlProgress(
            $this->analysisId,
            $this->websiteAnalysisId,
            \App\Enums\JobType::CrawlWebsite,
            $progress,
        );
    }

    /**
     * @param  list<string>  $allowedHosts
     */
    private function enqueue(string $url, int $depth, array $allowedHosts): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || ! in_array($host, $allowedHosts, true)) {
            return;
        }

        $page = new AnalysisCrawledPage;
        $page->website_analysis_id = $this->websiteAnalysisId;
        $page->url = $url;
        $page->depth = $depth;
        $page->discovered_via = 'link';
        $page->status = AnalysisCrawledPage::STATUS_PENDING;

        try {
            $page->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // 既出URL(url_hashのunique制約)。正常系のため無視する。
        }
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAnyPattern(string $subject, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && fnmatch($pattern, $subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * フロンティアの終端(上限到達・枯渇・想定外の失敗いずれも)。条件付き
     * レンダリング(依頼D-4)の対象を選び、対象があればRenderCrawledPageJobへ、
     * 無ければ直接ブランド・ホイール分析へ進む。
     */
    private function finalizeCrawl(AnalysisPipeline $pipeline, HtmlSeoAnalyzer $htmlSeoAnalyzer, string $reason): void
    {
        $counts = $this->pages()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        Log::info('brand_wheel_crawl_completed', [
            'analysis_id' => $this->analysisId,
            'website_analysis_id' => $this->websiteAnalysisId,
            'reason' => $reason,
            'counts' => $counts,
        ]);

        $minChars = (int) config('brand_wheel.crawl_render_candidate_min_chars', 200);
        $maxCandidates = (int) config('brand_wheel.crawl_render_candidate_max_count', 10);

        $candidates = $this->pages()
            ->where('status', AnalysisCrawledPage::STATUS_FETCHED)
            ->whereNotNull('raw_html_path')
            ->orderBy('depth')
            ->orderBy('id')
            ->get()
            ->filter(function (AnalysisCrawledPage $page) use ($htmlSeoAnalyzer, $minChars) {
                if (! Storage::disk('analysis')->exists($page->raw_html_path)) {
                    return false;
                }
                $html = Storage::disk('analysis')->get($page->raw_html_path);
                $body = $htmlSeoAnalyzer->extractBodyText($html, excludeNavigation: true);

                return mb_strlen($body) < $minChars;
            })
            ->take($maxCandidates);

        if ($candidates->isEmpty()) {
            $pipeline->dispatchBrandWheelAnalysisAfterCrawl($this->analysisId, $this->websiteAnalysisId);

            return;
        }

        AnalysisCrawledPage::query()
            ->whereIn('id', $candidates->pluck('id'))
            ->update(['render_candidate' => true]);

        Log::info('brand_wheel_crawl_render_candidates_selected', [
            'analysis_id' => $this->analysisId,
            'website_analysis_id' => $this->websiteAnalysisId,
            'candidate_count' => $candidates->count(),
        ]);

        // 依頼M-1: レンダリング対象が実際に決まった時点でRenderCrawledPagesを
        // Running化する(候補0件のときはPendingのまま、
        // dispatchBrandWheelAnalysisAfterCrawl()側で直接Completedになる)。
        $pipeline->markRunning($this->analysisId, $this->websiteAnalysisId, \App\Enums\JobType::RenderCrawledPages);

        RenderCrawledPageJob::dispatch($this->analysisId, $this->websiteAnalysisId, $candidates->count())->onQueue('analysis-heavy');
    }
}
