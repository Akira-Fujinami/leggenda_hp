<?php

namespace App\Jobs\Analysis;

use App\Enums\JobType;
use App\Enums\PageType;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\CrawlLinkExtractor;
use App\Services\Analysis\CrawlPolicyResolver;
use App\Services\Analysis\RecruitmentTrackPageFilter;
use App\Services\Analysis\RobotsTxtParser;
use App\Services\Analysis\SitemapParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * サイト全ページ巡回(依頼C・D)の起点(seed専用)。2026-08-25(依頼D-1)に
 * 「1本の長時間ジョブ」から「1ジョブ=1ページ」の連鎖へ作り替えた ――
 * 本番のRenderはWeb Serviceプロセス内でキューワーカーが1本だけ全キューを
 * 直列処理する構成のため、旧実装(最大960秒の単一ジョブ)が走っている間
 * 他の全ジョブ(リード診断・レポート生成・通知)が止まる問題があった
 * (依頼者指摘)。このJobはrobots.txt判定・ホスト許可リストの解決・
 * 初期seed URLの収集だけを行い、実際のページ取得は行わない
 * (CrawlWebsitePageJobへ委譲)。
 *
 * $timeoutは短く保つ(このJob自体はHTTP取得を一切行わない、DB読み書きのみ)。
 */
class CrawlWebsiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 60;

    public function __construct(
        public readonly int $analysisId,
        public readonly int $websiteAnalysisId,
    ) {}

    public function handle(
        AnalysisPipeline $pipeline,
        CrawlPolicyResolver $policyResolver,
        RobotsTxtParser $robotsTxtParser,
        SitemapParser $sitemapParser,
        CrawlLinkExtractor $linkExtractor,
        RecruitmentTrackPageFilter $trackFilter,
    ): void {
        // 依頼M-1: 進捗表示用のAnalysisJob行(CrawlWebsite)をPending→Running
        // へ遷移させる。このJobはcrawl_site=trueのときにしかdispatchされない
        // (AnalysisPipeline::dispatchBrandWheelAnalysisIfDue()参照)ため、
        // 常に登録済みのはずだが、markRunning()自体もfirstOrCreate経由で
        // 冪等に動く。
        $pipeline->markRunning($this->analysisId, $this->websiteAnalysisId, JobType::CrawlWebsite);

        // 依頼BC-3: 起点URLが採用セクションの外(ルートパス+ホスト名に採用系
        // の語を含まない)にあり、区分の適用そのものを見送る場合、この
        // サイトの巡回について構造化ログを1件だけ出す(このJobはサイト単位で
        // 1回しか実行されないseed専用Jobのため、ここに置けば「1件」になる)。
        // 実際の見送り判定自体はCrawlWebsitePageJob側が独立に(状態を共有
        // せず)毎回再計算する ―― ここはログのためだけの事前確認。
        $websiteAnalysisForTrack = WebsiteAnalysis::query()->with(['analysis', 'website'])->find($this->websiteAnalysisId);
        $recruitmentTrackForLog = $websiteAnalysisForTrack?->analysis?->recruitment_track ?? 'unspecified';
        if ($recruitmentTrackForLog !== 'unspecified'
            && $websiteAnalysisForTrack?->website?->url !== null
            && ! $trackFilter->isOriginInsideRecruitSection($websiteAnalysisForTrack->website->url)
        ) {
            // ログにはURL・ホスト名・会社名・担当者名・メールアドレスを
            // 含めない(既存方針)。件数・IDに留める。
            Log::info('brand_wheel_crawl_recruitment_track_skipped_outside_recruit_section', [
                'analysis_id' => $this->analysisId,
                'website_analysis_id' => $this->websiteAnalysisId,
                'recruitment_track' => $recruitmentTrackForLog,
            ]);
        }

        $robotsDecision = $policyResolver->resolveRobotsPolicy($this->websiteAnalysisId, $robotsTxtParser);

        if ($robotsDecision === null) {
            // 依頼C-3: robots.txtが取得できていない(Unavailable、または
            // FetchRobotsJob自体が未完了/行が無い)場合はクロール自体を
            // 中止する。中止は異常ではなく正常な分岐として扱う。
            Log::info('brand_wheel_crawl_skipped', [
                'analysis_id' => $this->analysisId,
                'website_analysis_id' => $this->websiteAnalysisId,
                'reason' => 'robots_txt_unavailable',
            ]);
            // 依頼BV-1(2026-09-11): この経路はfinalizeCrawl()を通らないため、
            // 依頼BU-1のcrawl_finished_reason/atがnullのまま残り、画面が
            // 「巡回していません。」としか出せなかった(依頼者が実データ
            // (LINEヤフー)で確認)。上のLog::infoが出しているのと同じ値を
            // そのまま保存する ―― 判定ロジック・ログ出力は変えない、
            // 記録の追加のみ。
            WebsiteAnalysis::query()->whereKey($this->websiteAnalysisId)->update([
                'crawl_finished_reason' => 'robots_txt_unavailable',
                'crawl_finished_at' => now(),
            ]);
            $pipeline->dispatchBrandWheelAnalysisAfterCrawl($this->analysisId, $this->websiteAnalysisId);

            return;
        }

        $allowedHosts = $policyResolver->allowedHosts($this->websiteAnalysisId);

        if ($allowedHosts === []) {
            Log::warning('CrawlWebsiteJob: no allowed hosts resolved (homepage/recruit final_url missing), skipping', [
                'analysis_id' => $this->analysisId,
                'website_analysis_id' => $this->websiteAnalysisId,
            ]);
            // 依頼BV-1: 依頼者提案の値('no_allowed_hosts')。上のrobots同様、
            // finalizeCrawl()を通らないため、ここで記録する。
            WebsiteAnalysis::query()->whereKey($this->websiteAnalysisId)->update([
                'crawl_finished_reason' => 'no_allowed_hosts',
                'crawl_finished_at' => now(),
            ]);
            $pipeline->dispatchBrandWheelAnalysisAfterCrawl($this->analysisId, $this->websiteAnalysisId);

            return;
        }

        $homepage = AnalysisPage::query()->where('website_analysis_id', $this->websiteAnalysisId)->where('page_type', PageType::Homepage)->first();
        $recruit = AnalysisPage::query()->where('website_analysis_id', $this->websiteAnalysisId)->where('page_type', PageType::Recruit)->first();

        $seededCount = 0;

        // seed 1: トップページ・採用ページ自身の本文中のリンク。この2ページは
        // seedとして使うが再取得しない(依頼C-6、Phase 1の設計を維持)。
        foreach ([$homepage, $recruit] as $seedPage) {
            if ($seedPage === null || $seedPage->raw_html_path === null) {
                continue;
            }
            $html = $policyResolver->readStoredFile($seedPage->raw_html_path);
            if ($html === null) {
                continue;
            }
            $pageUrl = $seedPage->final_url ?? $seedPage->url;
            foreach ($linkExtractor->extractAbsoluteLinks($html, $pageUrl) as $link) {
                if ($this->enqueueSeed($link, 1, 'link', $allowedHosts)) {
                    $seededCount++;
                }
            }
        }

        // seed 2: sitemap.xml由来のURL(urlsetのみ、依頼C-2)。
        $sitemapPage = AnalysisPage::query()->where('website_analysis_id', $this->websiteAnalysisId)->where('page_type', PageType::Sitemap)->first();
        if ($sitemapPage !== null && $sitemapPage->raw_html_path !== null) {
            $xml = $policyResolver->readStoredFile($sitemapPage->raw_html_path);
            if ($xml !== null) {
                $parsedSitemap = $sitemapParser->parse($xml);
                foreach ($parsedSitemap['urls'] as $url) {
                    if ($this->enqueueSeed($url, 1, 'sitemap', $allowedHosts)) {
                        $seededCount++;
                    }
                }
            }
        }

        Log::info('brand_wheel_crawl_seeded', [
            'analysis_id' => $this->analysisId,
            'website_analysis_id' => $this->websiteAnalysisId,
            'seeded_count' => $seededCount,
        ]);

        if ($seededCount === 0) {
            // 巡回対象0件(例: 採用ページが独立マイクロサイトで、サイト内
            // リンクが許可ホストの外(親ブランドドメイン等)にしか無いケース。
            // 依頼D中間測定でSmartHRが実際にこの経路を通ることを確認済み)。
            $pipeline->dispatchBrandWheelAnalysisAfterCrawl($this->analysisId, $this->websiteAnalysisId);

            return;
        }

        // 最初の1ページ目を処理するジョブを起動する(間隔を空ける必要が
        // 無い最初の1件は遅延なしでdispatchする)。
        CrawlWebsitePageJob::dispatch($this->analysisId, $this->websiteAnalysisId)->onQueue('analysis');
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);
        Log::error('CrawlWebsiteJob failed unexpectedly, finalizing without crawling', [
            'analysis_id' => $this->analysisId,
            'website_analysis_id' => $this->websiteAnalysisId,
            'exception' => $exception->getMessage(),
        ]);
        app(AnalysisPipeline::class)->dispatchBrandWheelAnalysisAfterCrawl($this->analysisId, $this->websiteAnalysisId);
    }

    /**
     * @param  list<string>  $allowedHosts
     */
    private function enqueueSeed(string $url, int $depth, string $discoveredVia, array $allowedHosts): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || ! in_array($host, $allowedHosts, true)) {
            return false;
        }

        // url_hashのunique制約(website_analysis_id, url_hash)により、同一URLが
        // 複数の発見元(本文リンク・sitemap)から重複して見つかっても1行にしか
        // ならない ―― 旧実装のインメモリ$visitedと同じ役割をDB制約が担う。
        $page = new AnalysisCrawledPage;
        $page->website_analysis_id = $this->websiteAnalysisId;
        $page->url = $url;
        $page->depth = $depth;
        $page->discovered_via = $discoveredVia;
        $page->status = AnalysisCrawledPage::STATUS_PENDING;

        try {
            $page->save();

            return true;
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // 既に同一URLの行が存在する(重複seed)。正常系のため無視する。
            return false;
        }
    }
}
