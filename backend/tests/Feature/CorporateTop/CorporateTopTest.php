<?php

namespace Tests\Feature\CorporateTop;

use App\Enums\PageType;
use App\Jobs\FetchCorporateTopJob;
use App\Jobs\GenerateBrandWheelAnalysisJob;
use App\Models\AnalysisCrawledPage;
use App\Models\BrandWheelAnalysisResult;
use App\Models\LeadCompany;
use App\Models\WebsiteAnalysis;
use App\Services\Admin\LeadCompanyDeletionService;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\FetchResult;
use App\Services\Analysis\SafeHttpFetcher;
use App\Services\BrandWheel\BrandWheelAnalysisInputFactory;
use App\Services\CorporateTop\CorporateTopService;
use App\Services\CorporateTop\CorporateTopStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;

/**
 * 依頼CR-1: 階層図のTOPにするコーポレートサイトのTOP。候補の決め方・採用リンクの確認・保存・失敗時。
 * 通信は行わない(取得処理は固定の応答を返す偽物に差し替える)。
 */
class CorporateTopTest extends TestCase
{
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);
    }

    /**
     * @param  array<string, string|\Throwable|int>  $pages  URL => HTML(文字列) / 例外 / HTTPステータス
     * @return object{requested: list<string>}
     */
    private function fakeFetcher(array $pages): object
    {
        $fetcher = new class($pages) extends SafeHttpFetcher
        {
            /** @var list<string> */
            public array $requested = [];

            public function __construct(private readonly array $pages) {}

            public function fetch(string $url, array $allowedContentTypePrefixes = [], ?int $totalTimeoutSeconds = null): FetchResult
            {
                $this->requested[] = $url;
                $page = $this->pages[$url] ?? 404;
                if ($page instanceof \Throwable) {
                    throw $page;
                }
                if (is_int($page)) {
                    return new FetchResult($url, $url, $page, '', 'text/html', 1);
                }

                return new FetchResult($url, $url, 200, $page, 'text/html', 1);
            }
        };
        app()->instance(SafeHttpFetcher::class, $fetcher);

        return $fetcher;
    }

    private function selfWithOrigin(string $originUrl): WebsiteAnalysis
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, $originUrl, $this->htmlPage('採用', '採用です。'), PageType::Recruit);

        return $wa;
    }

    private function service(): CorporateTopService
    {
        return app(CorporateTopService::class);
    }

    public function test_the_candidate_for_a_path_origin_is_the_top_of_the_same_host(): void
    {
        [$candidates, $skipped] = $this->service()->candidates('https://www.example.com/recruit');

        $this->assertSame(['https://www.example.com/'], $candidates);
        $this->assertNull($skipped);
    }

    public function test_an_origin_already_at_the_top_has_no_same_host_candidate(): void
    {
        [$candidates] = $this->service()->candidates('https://example.com/');

        $this->assertSame([], $candidates);
    }

    public function test_a_recruit_subdomain_gives_the_bare_and_www_hosts(): void
    {
        [$candidates] = $this->service()->candidates('https://recruit.example.com/');
        $this->assertSame(['https://example.com/', 'https://www.example.com/'], $candidates);

        foreach (['careers', 'career', 'jobs', 'saiyo', 'hr'] as $label) {
            [$candidates] = $this->service()->candidates("https://{$label}.example.co.jp/");
            $this->assertSame(["https://example.co.jp/", "https://www.example.co.jp/"], $candidates, $label);
        }
    }

    public function test_a_recruit_subdomain_with_a_path_also_tries_the_same_host_top_first(): void
    {
        [$candidates] = $this->service()->candidates('https://recruit.example.com/fresh/');

        $this->assertSame(['https://recruit.example.com/', 'https://example.com/', 'https://www.example.com/'], $candidates);
    }

    public function test_a_hyphen_recruit_host_makes_no_host_candidates_and_the_reason_is_reported(): void
    {
        [$candidates, $skipped] = $this->service()->candidates('https://example-recruit.example.com/');

        $this->assertSame([], $candidates);
        $this->assertSame('hyphen_recruit_host_not_supported', $skipped);
    }

    public function test_it_adopts_the_page_that_links_to_the_recruit_origin_and_uses_the_link_text(): void
    {
        $wa = $this->selfWithOrigin('https://www.example.com/recruit');
        $fetcher = $this->fakeFetcher([
            'https://www.example.com/' => '<html><body><header><nav><a href="/about">会社情報</a><a href="/recruit/">採用情報</a></nav></header></body></html>',
        ]);

        $meta = $this->service()->run($wa);

        $this->assertSame('found', $meta['status']);
        $this->assertSame('採用情報', $meta['recruit_label']);
        $this->assertSame('https://www.example.com/recruit/', $meta['recruit_url']);
        $this->assertSame(['https://www.example.com/'], $fetcher->requested);
        $this->assertNotNull(app(CorporateTopStore::class)->readHtml($wa->analysis_id, $wa->id));
        Storage::disk('analysis')->assertExists("analyses/{$wa->analysis_id}/websites/{$wa->id}/raw/corporate_top.html");
    }

    public function test_a_link_to_a_page_under_the_origin_counts_and_an_empty_text_falls_back_to_alt_then_aria_label(): void
    {
        $wa = $this->selfWithOrigin('https://www.example.com/recruit/');
        $this->fakeFetcher(['https://www.example.com/' => '<a href="/recruit/fresh/"><img src="x.png" alt="RECRUIT"></a>']);
        $this->assertSame('RECRUIT', $this->service()->run($wa, true)['recruit_label']);

        $this->fakeFetcher(['https://www.example.com/' => '<a href="/recruit/" aria-label="採用はこちら"></a>']);
        $this->assertSame('採用はこちら', $this->service()->run($wa, true)['recruit_label']);

        $this->fakeFetcher(['https://www.example.com/' => '<a href="/recruit/"></a>']);
        $meta = $this->service()->run($wa, true);
        $this->assertSame('found', $meta['status']);
        $this->assertNull($meta['recruit_label'], '空のときはnull ―― 階層図が既定の「採用情報」にする');
    }

    public function test_a_long_banner_text_is_not_used_as_the_label_and_a_link_to_the_origin_wins_over_deeper_links(): void
    {
        $wa = $this->selfWithOrigin('https://www.example.com/recruit/');
        $banner = str_repeat('中途採用のバナーの長い説明文です。', 4);

        // 起点そのものへのリンクが無い: 文字が長いバナーより、短い文字のリンクを選ぶ(浅いパスを先に)。
        $this->fakeFetcher(['https://www.example.com/' => '<a href="/recruit/career/jobs/">'.$banner.'</a><a href="/recruit/career/x/y/">深い</a><a href="/recruit/fresh/">Recruit</a>']);
        $this->assertSame('Recruit', $this->service()->run($wa, true)['recruit_label']);

        // 起点そのものへのリンクがあれば、それが最優先。
        $this->fakeFetcher(['https://www.example.com/' => '<a href="/recruit/fresh/">Recruit</a><a href="/recruit">採用情報</a>']);
        $this->assertSame('採用情報', $this->service()->run($wa, true)['recruit_label']);

        // 長い文字のリンクしか無い: 採用はしてよいが、文字は使わない(既定の文言になる)。
        $this->fakeFetcher(['https://www.example.com/' => '<a href="/recruit/">'.$banner.'</a>']);
        $meta = $this->service()->run($wa, true);
        $this->assertSame('found', $meta['status']);
        $this->assertNull($meta['recruit_label']);
    }

    public function test_a_page_without_a_link_to_the_recruit_site_is_not_adopted_and_the_next_candidate_is_tried(): void
    {
        $wa = $this->selfWithOrigin('https://recruit.example.com/');
        $fetcher = $this->fakeFetcher([
            'https://example.com/' => '<a href="/about">会社情報</a><a href="https://other.example.org/recruit/">別の会社</a>',
            'https://www.example.com/' => '<a href="https://recruit.example.com/entry/">エントリー</a>',
        ]);

        $meta = $this->service()->run($wa);

        $this->assertSame('found', $meta['status']);
        $this->assertSame('https://www.example.com/', $meta['candidate']);
        $this->assertSame(['https://example.com/', 'https://www.example.com/'], $fetcher->requested);
        $this->assertSame(['no_recruit_link', 'ok'], array_column($meta['attempts'], 'outcome'));
    }

    public function test_when_no_candidate_passes_nothing_is_adopted_and_no_html_is_saved(): void
    {
        $wa = $this->selfWithOrigin('https://www.example.com/recruit');
        $this->fakeFetcher(['https://www.example.com/' => '<a href="/about">会社情報</a>']);

        $meta = $this->service()->run($wa);

        $this->assertSame('not_found', $meta['status']);
        $this->assertNull(app(CorporateTopStore::class)->readHtml($wa->analysis_id, $wa->id));
        $this->assertSame('not_found', app(CorporateTopStore::class)->readMeta($wa->analysis_id, $wa->id)['status']);
    }

    public function test_a_fetch_failure_does_not_throw_and_is_recorded(): void
    {
        $wa = $this->selfWithOrigin('https://www.example.com/recruit');
        $this->fakeFetcher(['https://www.example.com/' => new \RuntimeException('boom')]);

        $meta = $this->service()->run($wa);

        $this->assertSame('not_found', $meta['status']);
        $this->assertSame(['fetch_failed'], array_column($meta['attempts'], 'outcome'));
    }

    public function test_the_hyphen_recruit_host_is_not_fetched_at_all(): void
    {
        $wa = $this->selfWithOrigin('https://example-recruit.example.com/');
        $fetcher = $this->fakeFetcher([]);

        $meta = $this->service()->run($wa);

        $this->assertSame([], $fetcher->requested);
        $this->assertSame('hyphen_recruit_host_not_supported', $meta['skipped']);
    }

    public function test_an_existing_result_is_not_fetched_again(): void
    {
        $wa = $this->selfWithOrigin('https://www.example.com/recruit');
        $fetcher = $this->fakeFetcher(['https://www.example.com/' => '<a href="/recruit/">採用情報</a>']);

        $this->service()->run($wa);
        $this->service()->run($wa);

        $this->assertCount(1, $fetcher->requested);
    }

    public function test_the_fetch_does_not_touch_the_crawled_pages_nor_the_brand_wheel_input(): void
    {
        $wa = $this->selfWithOrigin('https://self.example.com/recruit/');
        $this->seedStandardCompany($wa, 'https://self.example.com/recruit');
        $crawledBefore = AnalysisCrawledPage::query()->where('website_analysis_id', $wa->id)->orderBy('id')->pluck('url')->all();
        $inputBefore = app(BrandWheelAnalysisInputFactory::class)->build($wa)->toArray();
        $this->fakeFetcher(['https://self.example.com/' => '<a href="/recruit/">採用情報</a>']);

        $this->service()->run($wa);

        $this->assertSame($crawledBefore, AnalysisCrawledPage::query()->where('website_analysis_id', $wa->id)->orderBy('id')->pluck('url')->all(), '巡回の件数・順は同じ');
        $this->assertSame($inputBefore, app(BrandWheelAnalysisInputFactory::class)->build($wa)->toArray(), 'ブランド・ホイールの入力は同じ');
    }

    public function test_the_files_are_deleted_with_the_company_data(): void
    {
        $wa = $this->selfWithOrigin('https://www.example.com/recruit');
        $this->fakeFetcher(['https://www.example.com/' => '<a href="/recruit/">採用情報</a>']);
        $this->service()->run($wa);
        $html = "analyses/{$wa->analysis_id}/websites/{$wa->id}/raw/corporate_top.html";
        $meta = "analyses/{$wa->analysis_id}/websites/{$wa->id}/metadata/corporate_top.json";
        Storage::disk('analysis')->assertExists([$html, $meta]);

        $company = LeadCompany::query()->findOrFail($wa->analysis->project->lead_company_id);
        app(LeadCompanyDeletionService::class)->destroy($company, $company->company_name);

        Storage::disk('analysis')->assertMissing([$html, $meta]);
    }

    // ---------------------------------------------------------------- 起動

    private function runBrandWheel(WebsiteAnalysis $wa): void
    {
        $record = BrandWheelAnalysisResult::factory()->create(['analysis_id' => $wa->analysis_id, 'website_analysis_id' => $wa->id, 'status' => 'pending', 'is_mock' => false]);
        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));
    }

    public function test_the_job_is_dispatched_for_the_primary_site_of_a_comparison_only_once_the_brand_wheel_finishes(): void
    {
        Queue::fake();
        $self = $this->makeComparisonWebsiteAnalysis();
        $competitor = $this->makeComparisonWebsiteAnalysis(false, 1, 'competitor', $self->analysis);
        $this->seedStandardCompany($self);

        $this->runBrandWheel($self);
        $this->runBrandWheel($competitor);

        Queue::assertPushed(FetchCorporateTopJob::class, 1);
        Queue::assertPushed(FetchCorporateTopJob::class, fn (FetchCorporateTopJob $job) => $job->websiteAnalysisId === $self->id);
    }

    public function test_the_job_is_not_dispatched_when_a_result_exists_or_the_switch_is_off(): void
    {
        Queue::fake();
        $wa = $this->makeComparisonWebsiteAnalysis();
        app(CorporateTopStore::class)->write($wa->analysis_id, $wa->id, ['status' => 'not_found'], null);
        $this->runBrandWheel($wa);
        Queue::assertNotPushed(FetchCorporateTopJob::class);

        config(['admin_comparison_pptx.corporate_top_enabled' => false]);
        $other = $this->makeComparisonWebsiteAnalysis();
        $this->runBrandWheel($other);
        Queue::assertNotPushed(FetchCorporateTopJob::class);
    }

    public function test_a_failing_job_never_fails_the_comparison(): void
    {
        $wa = $this->selfWithOrigin('https://www.example.com/recruit');
        app()->instance(CorporateTopService::class, new class extends CorporateTopService
        {
            public function __construct() {}

            public function run(WebsiteAnalysis $websiteAnalysis, bool $force = false): array
            {
                throw new \RuntimeException('想定外');
            }
        });

        (new FetchCorporateTopJob($wa->id))->handle(app(CorporateTopService::class));

        $this->assertTrue(true, '例外がジョブの外へ出ない');
    }
}
