<?php

namespace Tests\Feature\Analysis;

use App\Enums\PageType;
use App\Models\Analysis;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\ComparisonMaterialBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 依頼DS-1: 自社と競合で、比べる材料の量がそろっていないときの注記。
 */
class ComparisonMaterialBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Analysis $analysis;

    private WebsiteAnalysis $self;

    private WebsiteAnalysis $rival;

    protected function setUp(): void
    {
        parent::setUp();
        $project = Project::factory()->create();
        $this->analysis = Analysis::factory()->for($project)->create(['crawl_site' => true]);
        $this->self = $this->site($project, 'https://self.example.co.jp/recruit/', true);
        $this->rival = $this->site($project, 'https://rival.example.co.jp/recruit/', false);
    }

    private function site(Project $project, string $url, bool $primary): WebsiteAnalysis
    {
        $wa = WebsiteAnalysis::factory()->create([
            'analysis_id' => $this->analysis->id,
            'website_id' => Website::factory()->for($project)->create(['is_primary' => $primary, 'url' => $url])->id,
        ]);
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => $url, 'final_url' => $url, 'http_status' => 200]);

        return $wa;
    }

    private function fetched(WebsiteAnalysis $wa, int $within, int $outside = 0): void
    {
        $host = parse_url((string) $wa->website->url, PHP_URL_HOST);
        $urls = [];
        for ($i = 0; $i < $within; $i++) {
            $urls[] = "https://{$host}/recruit/p{$i}/";
        }
        for ($i = 0; $i < $outside; $i++) {
            $urls[] = "https://{$host}/company/p{$i}/";
        }
        foreach ($urls as $url) {
            $page = new AnalysisCrawledPage;
            $page->website_analysis_id = $wa->id;
            $page->url = $url;
            $page->final_url = $url;
            $page->depth = 1;
            $page->discovered_via = 'link';
            $page->status = AnalysisCrawledPage::STATUS_FETCHED;
            $page->save();
        }
    }

    private function robots(WebsiteAnalysis $wa, int $status): void
    {
        $wa->update(['crawl_finished_reason' => 'robots_txt_unavailable', 'crawl_finished_at' => now()]);
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Robots, 'url' => 'https://x.example.co.jp/robots.txt', 'http_status' => $status]);
    }

    private function notice(): ?array
    {
        return app(ComparisonMaterialBalanceService::class)->notice($this->analysis->fresh());
    }

    public function test_when_the_own_site_blocked_the_crawler_the_notice_says_so(): void
    {
        $this->robots($this->self, 403);
        $this->fetched($this->rival, 1, 49);

        $notice = $this->notice();

        $this->assertSame('self_blocked', $notice['code']);
        $this->assertStringContainsString('アクセスを制限', $notice['message']);
    }

    public function test_when_the_own_site_was_not_crawled_for_another_reason(): void
    {
        $this->self->update(['crawl_finished_reason' => 'no_seed_urls_found', 'crawl_finished_at' => now()]);
        $this->fetched($this->rival, 10);

        $this->assertSame('self_not_crawled', $this->notice()['code']);
    }

    public function test_when_the_competitor_blocked_the_crawler_this_notice_is_left_to_the_competitor_notice(): void
    {
        $this->robots($this->rival, 403);
        $this->fetched($this->self, 10);

        $this->assertNull($this->notice());
    }

    public function test_when_the_competitor_was_not_crawled_for_another_reason(): void
    {
        $this->robots($this->rival, 503);
        $this->fetched($this->self, 10);

        $this->assertSame('competitor_not_crawled', $this->notice()['code']);
    }

    public function test_a_large_gap_in_pages_under_the_recruit_page_is_noted_with_the_counts(): void
    {
        $this->fetched($this->self, 2, 48);
        $this->fetched($this->rival, 30, 5);

        $notice = $this->notice();

        $this->assertSame('origin_imbalance', $notice['code']);
        $this->assertStringContainsString('御社（2ページ）と競合（30ページ）', $notice['message']);
    }

    public function test_no_notice_when_the_material_is_balanced_or_the_gap_is_small(): void
    {
        $this->fetched($this->self, 3);
        $this->fetched($this->rival, 9);

        $this->assertNull($this->notice(), '差が10ページ未満なら出さない');
    }

    public function test_no_notice_when_the_site_is_not_crawled_in_this_diagnosis(): void
    {
        $this->analysis->update(['crawl_site' => false]);
        $this->fetched($this->rival, 30);

        $this->assertNull($this->notice());
    }
}
