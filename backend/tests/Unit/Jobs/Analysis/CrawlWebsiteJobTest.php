<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisStatus;
use App\Enums\PageType;
use App\Jobs\Analysis\CrawlWebsiteJob;
use App\Jobs\Analysis\CrawlWebsitePageJob;
use App\Models\Analysis;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\BrandWheelAnalysisResult;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\Analysis\CrawlLinkExtractor;
use App\Services\Analysis\CrawlPolicyResolver;
use App\Services\Analysis\RobotsTxtParser;
use App\Services\Analysis\SitemapParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼D-1: CrawlWebsiteJobは2026-08-25にseed専用へ作り替えた ―― 実際の
 * ページ取得(旧・依頼Cのテストが検証していたrobots.txt遵守/ホスト許可
 * リスト/上限判定/1ページ失敗の継続性)はCrawlWebsitePageJobTestへ移した。
 * このテストはseedの収集(本文リンク・sitemap・重複排除)と、robots判定
 * 結果に応じた分岐(中止 or 1ページ目のCrawlWebsitePageJobをdispatch)のみを
 * 検証する。
 */
class CrawlWebsiteJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsiteAnalysis(
        bool $withRecruit = false,
        string $homepageHost = 'example.co.jp',
        ?string $recruitHost = null,
    ): array {
        $project = Project::factory()->create();
        $analysis = Analysis::factory()->for($project)->create([
            'status' => AnalysisStatus::Running,
            'crawl_site' => true,
        ]);
        $website = Website::factory()->for($project)->create(['is_primary' => true, 'url' => "https://{$homepageHost}"]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        $homepageHtml = '<html><body><p>会社の紹介文です。</p>'.
            "<a href=\"https://{$homepageHost}/about\">about</a>".
            '</body></html>';
        $homepagePath = app(AnalysisStoragePaths::class)->rawHtmlPath($analysis->id, $websiteAnalysis->id, 'homepage.html');
        Storage::disk('analysis')->put($homepagePath, $homepageHtml);

        AnalysisPage::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'page_type' => PageType::Homepage,
            'url' => "https://{$homepageHost}",
            'final_url' => "https://{$homepageHost}",
            'http_status' => 200,
            'raw_html_path' => $homepagePath,
        ]);

        if ($withRecruit) {
            $recruitHost ??= "recruit.{$homepageHost}";
            $recruitPath = app(AnalysisStoragePaths::class)->rawHtmlPath($analysis->id, $websiteAnalysis->id, 'recruit.html');
            Storage::disk('analysis')->put($recruitPath, '<html><body><p>採用情報です。</p></body></html>');

            AnalysisPage::factory()->create([
                'website_analysis_id' => $websiteAnalysis->id,
                'page_type' => PageType::Recruit,
                'url' => "https://{$recruitHost}/",
                'final_url' => "https://{$recruitHost}/",
                'http_status' => 200,
                'raw_html_path' => $recruitPath,
            ]);
        }

        return [$analysis, $websiteAnalysis];
    }

    private function putRobotsPage(Analysis $analysis, WebsiteAnalysis $websiteAnalysis, ?int $httpStatus, ?string $content = null): void
    {
        if ($httpStatus === null) {
            // robots.txt行自体が無い(FetchRobotsJob未完了/失敗)状態を再現する
            // ため、あえて行を作らない。
            return;
        }

        $robotsPath = null;
        if ($httpStatus === 200) {
            $robotsPath = app(AnalysisStoragePaths::class)->rawHtmlPath($analysis->id, $websiteAnalysis->id, 'robots.txt');
            Storage::disk('analysis')->put($robotsPath, $content ?? '');
        }

        AnalysisPage::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'page_type' => PageType::Robots,
            'url' => 'https://example.co.jp/robots.txt',
            'http_status' => $httpStatus,
            'raw_html_path' => $robotsPath,
        ]);
    }

    private function handle(Analysis $analysis, WebsiteAnalysis $websiteAnalysis): void
    {
        (new CrawlWebsiteJob($analysis->id, $websiteAnalysis->id))->handle(
            app(AnalysisPipeline::class),
            app(CrawlPolicyResolver::class),
            app(RobotsTxtParser::class),
            app(SitemapParser::class),
            app(CrawlLinkExtractor::class),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
        config(['analysis.ssrf_test_allowlist' => 'example.co.jp,recruit.example.co.jp,otherco.co.jp,unrelated.example,www.example.co.jp']);
    }

    /**
     * robots.txtが取得できていない(行が無い=Unavailable相当)場合は
     * seedingを一切行わずクロール自体を中止し、ブランド・ホイール分析を
     * 直接起動する(依頼C-3の分岐を維持していることの確認)。
     */
    public function test_robots_txt_unavailable_aborts_seeding_and_dispatches_brand_wheel_directly(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        // putRobotsPageを呼ばない = robots行自体が無い。

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(0, AnalysisCrawledPage::query()->count());
        Queue::assertNotPushed(CrawlWebsitePageJob::class);
        $this->assertSame(1, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }

    /**
     * robots.txtがhttp_status=403(アクセス拒否)の場合も同様に中止する
     * ―― resolveRobotsPolicy()が200/404以外をUnavailable扱いするため。
     */
    public function test_robots_txt_403_aborts_seeding(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $this->putRobotsPage($analysis, $websiteAnalysis, 403);

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(0, AnalysisCrawledPage::query()->count());
        Queue::assertNotPushed(CrawlWebsitePageJob::class);
    }

    /**
     * robots.txt(404、制限なし)の場合、トップページ本文中のリンクが
     * `pending`行としてseedされ、CrawlWebsitePageJobが1件だけ(遅延なしで)
     * dispatchされる。
     */
    public function test_seeds_pending_rows_from_homepage_links_and_dispatches_the_first_page_job(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $this->putRobotsPage($analysis, $websiteAnalysis, 404);

        $this->handle($analysis, $websiteAnalysis);

        $row = AnalysisCrawledPage::query()->where('url', 'https://example.co.jp/about')->first();
        $this->assertNotNull($row);
        $this->assertSame(AnalysisCrawledPage::STATUS_PENDING, $row->status);
        $this->assertSame(1, $row->depth);
        $this->assertSame('link', $row->discovered_via);

        Queue::assertPushed(CrawlWebsitePageJob::class, fn ($job) => $job->analysisId === $analysis->id
            && $job->websiteAnalysisId === $websiteAnalysis->id
            && $job->delay === null);
        $this->assertSame(0, BrandWheelAnalysisResult::query()->count());
    }

    /**
     * ホスト許可リスト: 同一ホスト・採用サブドメインのリンクはseedされ、
     * 無関係な外部ドメイン・co.jpを共有するだけの別会社ドメインはseedされない
     * (依頼C-5と同じ判定をCrawlPolicyResolver経由で使っていることの確認)。
     */
    public function test_only_homepage_and_recruit_host_links_are_seeded(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis(withRecruit: true);
        $this->putRobotsPage($analysis, $websiteAnalysis, 404);

        $homepagePath = AnalysisPage::query()->where('website_analysis_id', $websiteAnalysis->id)->where('page_type', PageType::Homepage)->first()->raw_html_path;
        Storage::disk('analysis')->put($homepagePath, '<html><body>'.
            '<a href="https://example.co.jp/about">same host</a>'.
            '<a href="https://recruit.example.co.jp/jobs">recruit subdomain</a>'.
            '<a href="https://otherco.co.jp/">unrelated co.jp company</a>'.
            '<a href="https://unrelated.example/">totally unrelated</a>'.
            '</body></html>');

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(1, AnalysisCrawledPage::query()->where('url', 'https://example.co.jp/about')->count());
        $this->assertSame(1, AnalysisCrawledPage::query()->where('url', 'https://recruit.example.co.jp/jobs')->count());
        $this->assertSame(0, AnalysisCrawledPage::query()->where('url', 'like', '%otherco.co.jp%')->count());
        $this->assertSame(0, AnalysisCrawledPage::query()->where('url', 'like', '%unrelated.example%')->count());
    }

    /**
     * sitemap.xml由来のURLもseedされる(依頼C-2)。本文リンクと重複する
     * URLは1行にしかならない(url_hashのunique制約、旧実装のインメモリ
     * $visitedと同じ役割)。
     */
    public function test_sitemap_urls_are_seeded_and_duplicates_across_sources_are_deduplicated(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $this->putRobotsPage($analysis, $websiteAnalysis, 404);

        $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>'.
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.
            '<url><loc>https://example.co.jp/about</loc></url>'.
            '<url><loc>https://example.co.jp/news</loc></url>'.
            '</urlset>';
        $sitemapPath = app(AnalysisStoragePaths::class)->rawHtmlPath($analysis->id, $websiteAnalysis->id, 'sitemap.xml');
        Storage::disk('analysis')->put($sitemapPath, $sitemapXml);
        AnalysisPage::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'page_type' => PageType::Sitemap,
            'url' => 'https://example.co.jp/sitemap.xml',
            'http_status' => 200,
            'raw_html_path' => $sitemapPath,
        ]);

        $this->handle($analysis, $websiteAnalysis);

        // /about は本文リンクとsitemapの両方に登場するが1行のみ。
        $this->assertSame(1, AnalysisCrawledPage::query()->where('url', 'https://example.co.jp/about')->count());
        $newsRow = AnalysisCrawledPage::query()->where('url', 'https://example.co.jp/news')->first();
        $this->assertNotNull($newsRow);
        $this->assertSame('sitemap', $newsRow->discovered_via);
    }

    /**
     * urlが長い(varchar(2048)近い)場合でもseed行のINSERTが通ること
     * (url_hashに対するunique制約、依頼C-4)。
     */
    public function test_a_very_long_url_can_be_seeded_without_a_database_error(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $this->putRobotsPage($analysis, $websiteAnalysis, 404);

        $longPath = '/recruit/'.str_repeat('a', 2000);
        $longUrl = "https://example.co.jp{$longPath}";

        $homepagePath = AnalysisPage::query()->where('website_analysis_id', $websiteAnalysis->id)->where('page_type', PageType::Homepage)->first()->raw_html_path;
        Storage::disk('analysis')->put($homepagePath, '<html><body><a href="'.$longUrl.'">long</a></body></html>');

        $this->handle($analysis, $websiteAnalysis);

        $row = AnalysisCrawledPage::query()->where('url', $longUrl)->first();
        $this->assertNotNull($row);
        $this->assertSame(hash('sha256', $longUrl), $row->url_hash);
    }

    /**
     * seed対象0件(依頼D-3測定でSmartHRが実際に通ったケース ―― トップ
     * ページのリンクが全て許可ホスト外(親ブランドドメイン等)にしか
     * 無かった)の場合、CrawlWebsitePageJobをdispatchせずブランド・ホイール
     * 分析を直接起動する。
     */
    public function test_zero_seeded_pages_dispatches_brand_wheel_directly_without_crawling(): void
    {
        Queue::fake([CrawlWebsitePageJob::class]);
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();
        $this->putRobotsPage($analysis, $websiteAnalysis, 404);

        $homepagePath = AnalysisPage::query()->where('website_analysis_id', $websiteAnalysis->id)->where('page_type', PageType::Homepage)->first()->raw_html_path;
        Storage::disk('analysis')->put($homepagePath, '<html><body><a href="https://unrelated.example/jobs">external only</a></body></html>');

        $this->handle($analysis, $websiteAnalysis);

        $this->assertSame(0, AnalysisCrawledPage::query()->count());
        Queue::assertNotPushed(CrawlWebsitePageJob::class);
        $this->assertSame(1, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }

    /**
     * failed()経路からもブランド・ホイール分析が起動される(依頼D-2)。
     */
    public function test_failed_handler_dispatches_brand_wheel_directly(): void
    {
        [$analysis, $websiteAnalysis] = $this->makeWebsiteAnalysis();

        (new CrawlWebsiteJob($analysis->id, $websiteAnalysis->id))->failed(new \RuntimeException('boom'));

        $this->assertSame(1, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }

    /**
     * 依頼C-7: crawl_site=trueのときAnalysisPipeline::
     * dispatchBrandWheelAnalysisIfDue()はCrawlWebsiteJobをdispatchするだけで、
     * その場ではBrandWheelAnalysisResultを作らない(=GenerateBrandWheelAnalysisJobを
     * 直接起動しない)。クロール完了前にブランド・ホイール分析が走るレースを
     * 防ぐ、というオーケストレーションの核心を検証する。
     */
    public function test_dispatch_brand_wheel_analysis_if_due_defers_to_crawl_job_when_crawl_site_is_enabled(): void
    {
        Queue::fake();

        $project = Project::factory()->create();
        $analysis = Analysis::factory()->for($project)->create(['crawl_site' => true, 'skip_brand_wheel' => false]);
        $website = Website::factory()->for($project)->create(['is_primary' => true]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        app(AnalysisPipeline::class)->dispatchBrandWheelAnalysisIfDue($analysis->id, $websiteAnalysis->id);

        Queue::assertPushed(CrawlWebsiteJob::class, 1);
        Queue::assertNotPushed(\App\Jobs\GenerateBrandWheelAnalysisJob::class);
        $this->assertSame(0, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }

    /**
     * 回帰防止: crawl_site=false(既定)のときは、これまでどおり即座に
     * BrandWheelAnalysisResultが作られGenerateBrandWheelAnalysisJobが
     * 起動される(CrawlWebsiteJobは一切dispatchされない)。
     */
    public function test_dispatch_brand_wheel_analysis_if_due_runs_immediately_when_crawl_site_is_disabled(): void
    {
        Queue::fake();

        $project = Project::factory()->create();
        $analysis = Analysis::factory()->for($project)->create(['crawl_site' => false, 'skip_brand_wheel' => false]);
        $website = Website::factory()->for($project)->create(['is_primary' => true]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        app(AnalysisPipeline::class)->dispatchBrandWheelAnalysisIfDue($analysis->id, $websiteAnalysis->id);

        Queue::assertNotPushed(CrawlWebsiteJob::class);
        Queue::assertPushed(\App\Jobs\GenerateBrandWheelAnalysisJob::class, 1);
        $this->assertSame(1, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }
}
