<?php

namespace Tests\Feature\Admin;

use App\Enums\AnalysisStatus;
use App\Enums\WebsiteAnalysisStatus;
use App\Models\Analysis;
use App\Models\AnalysisCrawledPage;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 依頼BU(2026-09-11): 診断詳細画面(/admin/analyses/{id})の「巡回の実績」
 * 節。本番でメルカリ・DeNAの比較結果に説明のつかない数字が出た際、
 * 「巡回がページに届いていないのでは」という推測を確かめる手段が
 * どこにも無かった(依頼者指摘)。既存のanalysis_crawled_pages・
 * 依頼BU-1で追加したwebsite_analyses.crawl_finished_reason/atを
 * 表示するだけで、巡回のロジック自体は一切変えない(依頼者指定の範囲)。
 */
class CrawlDiagnosticsDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): static
    {
        return $this->withSession(['admin_authenticated' => true]);
    }

    private function makeAnalysis(bool $crawlSite = true): Analysis
    {
        $sentinel = User::factory()->create();
        $company = LeadCompany::factory()->create();
        $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);

        return Analysis::factory()->for($project)->create([
            'created_by' => $sentinel->id,
            'status' => AnalysisStatus::Completed,
            'crawl_site' => $crawlSite,
        ]);
    }

    private function makeWebsiteAnalysis(Analysis $analysis, string $name = '自社サイト'): WebsiteAnalysis
    {
        $website = Website::factory()->for($analysis->project)->create(['name' => $name]);

        return WebsiteAnalysis::factory()->create([
            'analysis_id' => $analysis->id,
            'website_id' => $website->id,
            'status' => WebsiteAnalysisStatus::Completed,
        ]);
    }

    /**
     * @param  array<string, int>  $statusCounts  例: ['fetched' => 3, 'failed' => 1]
     */
    private function seedCrawledPages(WebsiteAnalysis $wa, array $statusCounts, ?\DateTimeInterface $startedAt = null): void
    {
        foreach ($statusCounts as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                AnalysisCrawledPage::factory()->create([
                    'website_analysis_id' => $wa->id,
                    'status' => $status,
                    'http_status' => $status === AnalysisCrawledPage::STATUS_FAILED ? 500 : 200,
                    'created_at' => $startedAt ?? now(),
                ]);
            }
        }
    }

    public function test_all_finished_reasons_are_shown_in_japanese(): void
    {
        $reasons = [
            'exhausted' => 'リンクをたどり切って終了(正常)',
            'max_pages' => '上限ページ数に達して終了',
            'total_timeout' => '制限時間に達して終了(途中で切れています)',
            'max_storage' => '容量上限に達して終了',
            'robots_became_unavailable' => 'robots.txtが読めなくなり終了',
            'failed_exception' => '予期しないエラーで終了',
        ];

        foreach ($reasons as $reason => $expectedLabel) {
            $analysis = $this->makeAnalysis();
            $wa = $this->makeWebsiteAnalysis($analysis);
            $this->seedCrawledPages($wa, [AnalysisCrawledPage::STATUS_FETCHED => 20]);
            $wa->update(['crawl_finished_reason' => $reason, 'crawl_finished_at' => now()]);

            $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

            $response->assertOk();
            $response->assertSee($expectedLabel);
            $response->assertDontSee($reason);
        }
    }

    public function test_null_finished_reason_shows_unknown_and_does_not_break_the_page(): void
    {
        $analysis = $this->makeAnalysis();
        $wa = $this->makeWebsiteAnalysis($analysis);
        $this->seedCrawledPages($wa, [AnalysisCrawledPage::STATUS_FETCHED => 20]);
        // crawl_finished_reason/atは既定でnull(依頼BU-1適用前の既存データを再現)。

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee('不明');
    }

    public function test_counts_match_the_actual_crawled_page_data(): void
    {
        $analysis = $this->makeAnalysis();
        $wa = $this->makeWebsiteAnalysis($analysis);
        $this->seedCrawledPages($wa, [
            AnalysisCrawledPage::STATUS_FETCHED => 12,
            AnalysisCrawledPage::STATUS_FAILED => 2,
            AnalysisCrawledPage::STATUS_EXCLUDED_BY_PATTERN => 3,
            AnalysisCrawledPage::STATUS_EXCLUDED_BY_ROBOTS => 1,
            AnalysisCrawledPage::STATUS_EXCLUDED_BY_SCOPE => 4,
            AnalysisCrawledPage::STATUS_EXCLUDED_BY_TRACK => 5,
            AnalysisCrawledPage::STATUS_PENDING => 6,
        ]);
        AnalysisCrawledPage::query()
            ->where('website_analysis_id', $wa->id)
            ->where('status', AnalysisCrawledPage::STATUS_FETCHED)
            ->limit(7)
            ->update(['rendered_html_path' => 'dummy/rendered.html']);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        // 除外の内訳は「パターン/robots/対象外/新卒キャリア」の順で1セルに
        // まとめて表示している(CrawlDiagnosticsServiceTest側で内訳自体の
        // 正しさは別途検証、ここでは画面表示の一致のみ確認する)。
        $response->assertSee('3/1/4/5', false);
        $response->assertSee('>12<', false);
        $response->assertSee('>2<', false);
        $response->assertSee('>6<', false);
        $response->assertSee('>7<', false);
    }

    public function test_failed_urls_are_shown_with_http_status_and_overflow_count(): void
    {
        $analysis = $this->makeAnalysis();
        $wa = $this->makeWebsiteAnalysis($analysis);

        for ($i = 0; $i < 12; $i++) {
            AnalysisCrawledPage::factory()->create([
                'website_analysis_id' => $wa->id,
                'status' => AnalysisCrawledPage::STATUS_FAILED,
                'url' => "https://example.com/failed-page-{$i}",
                'http_status' => 404,
            ]);
        }
        $this->seedCrawledPages($wa, [AnalysisCrawledPage::STATUS_FETCHED => 20]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee('https://example.com/failed-page-0');
        $response->assertSee('https://example.com/failed-page-9');
        $response->assertDontSee('https://example.com/failed-page-10');
        $response->assertDontSee('https://example.com/failed-page-11');
        $response->assertSee('ほか2件');
        $response->assertSee('404');
    }

    public function test_warning_shows_when_fetched_page_count_is_low(): void
    {
        $analysis = $this->makeAnalysis();
        $wa = $this->makeWebsiteAnalysis($analysis);
        $this->seedCrawledPages($wa, [AnalysisCrawledPage::STATUS_FETCHED => 5]);
        $wa->update(['crawl_finished_reason' => 'exhausted', 'crawl_finished_at' => now()]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee(config('crawl_diagnostics.warning_messages.low_fetched_page_count'));
    }

    public function test_warning_does_not_show_when_fetched_page_count_is_sufficient(): void
    {
        $analysis = $this->makeAnalysis();
        $wa = $this->makeWebsiteAnalysis($analysis);
        $this->seedCrawledPages($wa, [AnalysisCrawledPage::STATUS_FETCHED => 20]);
        $wa->update(['crawl_finished_reason' => 'exhausted', 'crawl_finished_at' => now()]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertDontSee(config('crawl_diagnostics.warning_messages.low_fetched_page_count'));
        $response->assertDontSee(config('crawl_diagnostics.warning_messages.total_timeout'));
        $response->assertDontSee(config('crawl_diagnostics.warning_messages.high_failure_rate'));
    }

    public function test_warning_shows_when_finished_reason_is_total_timeout(): void
    {
        $analysis = $this->makeAnalysis();
        $wa = $this->makeWebsiteAnalysis($analysis);
        $this->seedCrawledPages($wa, [AnalysisCrawledPage::STATUS_FETCHED => 20]);
        $wa->update(['crawl_finished_reason' => 'total_timeout', 'crawl_finished_at' => now()]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee(config('crawl_diagnostics.warning_messages.total_timeout'));
    }

    public function test_warning_shows_when_failure_rate_is_high(): void
    {
        $analysis = $this->makeAnalysis();
        $wa = $this->makeWebsiteAnalysis($analysis);
        $this->seedCrawledPages($wa, [
            AnalysisCrawledPage::STATUS_FETCHED => 20,
            AnalysisCrawledPage::STATUS_FAILED => 9,
        ]);
        $wa->update(['crawl_finished_reason' => 'exhausted', 'crawl_finished_at' => now()]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee(config('crawl_diagnostics.warning_messages.high_failure_rate'));
    }

    public function test_warning_does_not_show_when_failure_rate_is_low(): void
    {
        $analysis = $this->makeAnalysis();
        $wa = $this->makeWebsiteAnalysis($analysis);
        $this->seedCrawledPages($wa, [
            AnalysisCrawledPage::STATUS_FETCHED => 20,
            AnalysisCrawledPage::STATUS_FAILED => 1,
        ]);
        $wa->update(['crawl_finished_reason' => 'exhausted', 'crawl_finished_at' => now()]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertDontSee(config('crawl_diagnostics.warning_messages.high_failure_rate'));
    }

    public function test_multi_site_comparison_shows_each_site_separately(): void
    {
        $analysis = $this->makeAnalysis();
        $self = $this->makeWebsiteAnalysis($analysis, '自社サイト(BU検証)');
        $competitor = $this->makeWebsiteAnalysis($analysis, '競合サイト(BU検証)');

        $this->seedCrawledPages($self, [AnalysisCrawledPage::STATUS_FETCHED => 20]);
        $self->update(['crawl_finished_reason' => 'exhausted', 'crawl_finished_at' => now()]);

        $this->seedCrawledPages($competitor, [AnalysisCrawledPage::STATUS_FETCHED => 3]);
        $competitor->update(['crawl_finished_reason' => 'max_pages', 'crawl_finished_at' => now()]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee('自社サイト(BU検証)');
        $response->assertSee('競合サイト(BU検証)');
        $response->assertSee('リンクをたどり切って終了(正常)');
        $response->assertSee('上限ページ数に達して終了');
        // 自社(20件取得、閾値10以上)には出ず、競合(3件、閾値未満)にだけ出る。
        $response->assertSee(config('crawl_diagnostics.warning_messages.low_fetched_page_count'));
    }

    public function test_crawl_site_false_analysis_does_not_break_the_page(): void
    {
        $analysis = $this->makeAnalysis(crawlSite: false);
        $this->makeWebsiteAnalysis($analysis);
        // analysis_crawled_pagesには一切行を作らない(crawl_site=falseの実態を再現)。

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee('巡回を行っていません');
    }

    public function test_no_lead_personal_information_is_shown_in_the_crawl_section(): void
    {
        $sentinel = User::factory()->create();
        $company = LeadCompany::factory()->create([
            'primary_contact_name' => 'BU検証担当太郎',
            'primary_contact_email' => 'bu-secret-contact@example.com',
        ]);
        $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);
        $analysis = Analysis::factory()->for($project)->create([
            'created_by' => $sentinel->id,
            'status' => AnalysisStatus::Completed,
            'crawl_site' => true,
        ]);
        $wa = $this->makeWebsiteAnalysis($analysis);
        $this->seedCrawledPages($wa, [AnalysisCrawledPage::STATUS_FETCHED => 5, AnalysisCrawledPage::STATUS_FAILED => 2]);
        $wa->update(['crawl_finished_reason' => 'exhausted', 'crawl_finished_at' => now()]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertDontSee('BU検証担当太郎');
        $response->assertDontSee('bu-secret-contact@example.com');
    }
}
