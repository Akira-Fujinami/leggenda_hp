<?php

namespace App\Console\Commands\Concerns;

use App\Enums\AnalysisStatus;
use App\Enums\PageType;
use App\Exceptions\Analysis\AnalysisException;
use App\Jobs\Analysis\CrawlWebsiteJob;
use App\Jobs\Analysis\CrawlWebsitePageJob;
use App\Jobs\Analysis\RenderCrawledPageJob;
use App\Models\Analysis;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\Analysis\SafeHttpFetcher;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼CQ-5: 開発用の測定コマンド(brand-wheel:measure-crawl-input / top-message:measure)が
 * 共有する、測定用サイトの固定定義・WebsiteAnalysisの作成・巡回の連鎖の再現。
 * MeasureBrandWheelCrawlInputCommandに在ったものを、そのまま(挙動を変えずに)移した。
 */
trait SeedsAndCrawlsMeasurementSites
{
    /**
     * 依頼D-7/E-7から引き継ぐ5サイトの固定定義。ラウンドを跨いでも同じURLで
     * 測定できるよう、ここに固定する(依頼者指摘 ―― 使い捨てスクリプトの
     * たびにURLを検索し直していたため、ラウンド間の比較が崩れていた)。
     * recruit_urlがhomepage_urlと同一のサイトは、採用ページとトップページが
     * 実際に同一URLである(自己参照)ことを表す。
     *
     * @var array<string, array{label: string, homepage_url: string, recruit_url: string}>
     */
    private const SITES = [
        'shinkin' => ['label' => 'しんきん', 'homepage_url' => 'https://www.shinkin.co.jp/ssc/recruit/index.html', 'recruit_url' => 'https://www.shinkin.co.jp/ssc/recruit/index.html'],
        'nttdata' => ['label' => 'NTTデータ', 'homepage_url' => 'https://www.nttdata.com/global/ja/recruit/', 'recruit_url' => 'https://www.nttdata.com/global/ja/recruit/'],
        'smarthr' => ['label' => 'SmartHR', 'homepage_url' => 'https://hello-world.smarthr.co.jp', 'recruit_url' => 'https://hello-world.smarthr.co.jp'],
        'kayac_recruit' => ['label' => 'カヤック(/recruit)', 'homepage_url' => 'https://www.kayac.com/recruit', 'recruit_url' => 'https://www.kayac.com/recruit'],
        'kayac' => ['label' => 'カヤック', 'homepage_url' => 'https://www.kayac.com/recruit/fresh', 'recruit_url' => 'https://www.kayac.com/recruit/fresh'],
        'cybozu' => ['label' => 'サイボウズ', 'homepage_url' => 'https://cybozu.co.jp/recruit/', 'recruit_url' => 'https://cybozu.co.jp/recruit/'],
        'moneyforward' => ['label' => 'マネーフォワード', 'homepage_url' => 'https://recruit.moneyforward.com/', 'recruit_url' => 'https://recruit.moneyforward.com/'],
        'freee' => ['label' => 'freee', 'homepage_url' => 'https://jobs.freee.co.jp/', 'recruit_url' => 'https://jobs.freee.co.jp/'],
        'kilfebon' => ['label' => 'キルフェボン', 'homepage_url' => 'https://www.quil-fait-bon-recruit.com', 'recruit_url' => 'https://www.quil-fait-bon-recruit.com'],
    ];

    /**
     * @return array{0: int, 1: int} [analysis_id, website_analysis_id]
     */
    private function seedWebsiteAnalysis(string $key, array $site, bool $crawlEnabled): array
    {
        $fetcher = app(SafeHttpFetcher::class);
        $paths = app(AnalysisStoragePaths::class);

        $project = Project::factory()->create();
        $analysis = Analysis::factory()->for($project)->create([
            'status' => AnalysisStatus::Running,
            'crawl_site' => $crawlEnabled,
            'skip_brand_wheel' => false,
        ]);
        $website = Website::factory()->for($project)->create([
            'is_primary' => true,
            'url' => $site['homepage_url'],
            'normalized_url' => $site['homepage_url'],
        ]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        $homepagePath = $paths->rawHtmlPath($analysis->id, $websiteAnalysis->id, 'homepage.html');
        $this->fetchAndSavePage($fetcher, $site['homepage_url'], $homepagePath, $websiteAnalysis->id, PageType::Homepage);

        $isSelfReference = $site['recruit_url'] === $site['homepage_url'];
        if ($isSelfReference) {
            // FetchRecruitPageJob::process()の自己参照検出(依頼B/優先度4-3)と
            // 同じ表現 ―― raw_html_pathを同一パスにする。
            // 依頼CO: 本番のFetchRecruitPageJobと同じく、トップページ行のurl・final_urlを
            // そのまま複製する(転送が起きたとき、実際のfinal_urlが起点の判定に効くため)。
            $homepageRow = AnalysisPage::query()->where('website_analysis_id', $websiteAnalysis->id)->where('page_type', PageType::Homepage)->first();
            AnalysisPage::query()->create([
                'website_analysis_id' => $websiteAnalysis->id,
                'page_type' => PageType::Recruit,
                'url' => $homepageRow?->url ?? $site['recruit_url'],
                'final_url' => $homepageRow?->final_url ?? $site['recruit_url'],
                'http_status' => $homepageRow?->http_status ?? 200,
                'raw_html_path' => $homepagePath,
                'fetched_at' => now(),
            ]);
        } else {
            $recruitPath = $paths->rawHtmlPath($analysis->id, $websiteAnalysis->id, 'recruit.html');
            $this->fetchAndSavePage($fetcher, $site['recruit_url'], $recruitPath, $websiteAnalysis->id, PageType::Recruit);
        }

        // FetchRobotsJobと同じURL構成(Website.normalized_urlに'/robots.txt'を
        // 追加するだけ)を用いる ―― サイト固有のドメインルート推定等の
        // 別ロジックを新設しない。
        $robotsUrl = rtrim($site['homepage_url'], '/').'/robots.txt';
        $robotsPath = $paths->rawHtmlPath($analysis->id, $websiteAnalysis->id, 'robots.txt');

        try {
            $result = $fetcher->fetch($robotsUrl);
            if ($result->httpStatus === 200) {
                Storage::disk('analysis')->put($robotsPath, $result->body);
            }
            AnalysisPage::query()->create([
                'website_analysis_id' => $websiteAnalysis->id,
                'page_type' => PageType::Robots,
                'url' => $robotsUrl,
                'final_url' => $result->finalUrl,
                'http_status' => $result->httpStatus,
                'raw_html_path' => $result->httpStatus === 200 ? $robotsPath : null,
                'fetched_at' => now(),
            ]);
        } catch (AnalysisException $e) {
            $this->warn("  robots.txt取得失敗({$key}): {$e->getMessage()}");
        }

        return [$analysis->id, $websiteAnalysis->id];
    }

    private function fetchAndSavePage(SafeHttpFetcher $fetcher, string $url, string $path, int $websiteAnalysisId, PageType $pageType): void
    {
        try {
            $result = $fetcher->fetch($url, ['text/html', 'application/xhtml+xml']);
            Storage::disk('analysis')->put($path, $result->body);

            AnalysisPage::query()->create([
                'website_analysis_id' => $websiteAnalysisId,
                'page_type' => $pageType,
                'url' => $result->requestedUrl,
                'final_url' => $result->finalUrl,
                'http_status' => $result->httpStatus,
                'content_type' => $result->contentType,
                'raw_html_path' => $path,
                'fetched_at' => now(),
            ]);
        } catch (AnalysisException $e) {
            $this->warn("  {$pageType->value}取得失敗({$url}): {$e->getMessage()}");
        }
    }

    /**
     * 依頼D-1のジョブ連鎖(CrawlWebsiteJob→CrawlWebsitePageJob→
     * RenderCrawledPageJob)を、このコマンドのプロセス内でhandle()を直接
     * 呼びながら再現する。「終端条件を先に見てから呼ぶ」のではなく「まず
     * 呼び、その結果として終端したかを都度確認する」順序を守ること ――
     * 依頼E-7の測定でこれを誤り、finalizeCrawl()を呼ぶはずの最後の1回を
     * 実行し損ねるバグを作り込んだため、修正済みの順序をここに引き継ぐ。
     */
    private function runCrawlChain(AnalysisPipeline $pipeline, int $analysisId, int $waId, bool $renderEnabled): void
    {
        // 依頼CM: Jobのhandle()に依存が増えるたびにこの手書きの引数列が壊れていた
        // (CrawlWebsiteJobは6引数になっていた)ため、コンテナに解決させる。
        app()->call([new CrawlWebsiteJob($analysisId, $waId), 'handle']);

        $intervalMicros = (int) round((float) config('brand_wheel.crawl_request_interval_seconds', 1.0) * 1_000_000);
        $maxPages = (int) config('brand_wheel.crawl_max_pages', 50);

        // 依頼G(2026-08-25): 以前はfetchedCount/hasPendingを「呼ぶ前に外部から
        // 判定」していたため、CrawlWebsitePageJob::handle()内部の終端判定
        // (呼び出し開始時点の状態で判定する)より1回早くループを抜けてしまい、
        // finalizeCrawl()を呼ぶはずの最後の1回を実行し損ねていた
        // (依頼者指摘・実測で確認: しんきんのように少数ページで自然に
        // フロンティアが枯渇するサイトでは即座に0件、大量ページのサイトでは
        // 逆に終端後もfinalizeCrawl()が繰り返し呼ばれ続けていた)。
        //
        // 本番のキュー連鎖(CrawlWebsitePageJob::dispatchNext())は、handle()
        // 内部からその場で無条件に次のジョブをdispatchするだけで、外部から
        // 「呼ぶべきか」を判定する層が存在しないため、この不具合は測定用の
        // このループに限られる(本番のCrawlWebsitePageJob自体・
        // CrawlWebsitePageJobTestは無関係、影響なし)。
        //
        // 修正: 毎回無条件にhandle()を呼び、「finalizeCrawl()が実際に走った
        // ことを示す観測可能な結果」(=候補が選定された、またはブランド・
        // ホイールへ直接dispatchされた)が出た直後に限ってループを止める。
        // 事前の条件判定でスキップしない(RenderCrawledPageJobループと
        // 同じ考え方)。
        for ($i = 0; $i < $maxPages * 5 + 50; $i++) {
            $fetchedCount = AnalysisCrawledPage::query()->where('website_analysis_id', $waId)->where('status', 'fetched')->count();
            $hasPending = AnalysisCrawledPage::query()->where('website_analysis_id', $waId)->where('status', 'pending')->exists();

            if ($fetchedCount < $maxPages && $hasPending) {
                usleep($intervalMicros);
            }

            app()->call([new CrawlWebsitePageJob($analysisId, $waId), 'handle']);

            $dispatched = WebsiteAnalysis::find($waId)?->brand_wheel_dispatched_at !== null;
            $hasRenderCandidates = AnalysisCrawledPage::query()->where('website_analysis_id', $waId)->where('render_candidate', true)->exists();

            if ($dispatched || $hasRenderCandidates) {
                // finalizeCrawl()がこの直前のhandle()呼び出しの中で実際に
                // 走った(0件で直接dispatchされたか、候補が選定されたか)。
                if ($dispatched) {
                    return;
                }

                break;
            }
        }

        if ($renderEnabled) {
            for ($i = 0; $i < 15; $i++) {
                if (WebsiteAnalysis::find($waId)?->brand_wheel_dispatched_at !== null) {
                    break;
                }
                app()->call([new RenderCrawledPageJob($analysisId, $waId), 'handle']);
            }
        } else {
            AnalysisCrawledPage::query()->where('website_analysis_id', $waId)->where('render_candidate', true)
                ->update(['render_candidate' => false]);
            if (WebsiteAnalysis::find($waId)?->brand_wheel_dispatched_at === null) {
                $pipeline->dispatchBrandWheelAnalysisAfterCrawl($analysisId, $waId);
            }
        }
    }
}
