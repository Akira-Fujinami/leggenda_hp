<?php

namespace Tests\Unit\Services\Admin;

use App\Models\Analysis;
use App\Models\AnalysisCrawledPage;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Admin\CrawlDiagnosticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 依頼BU: CrawlDiagnosticsServiceの集計ロジック単体の検証
 * (CrawlDiagnosticsDisplayTestは画面表示の確認、こちらは件数・閾値判定
 * そのものの正しさを確認する)。
 */
class CrawlDiagnosticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsiteAnalysis(): WebsiteAnalysis
    {
        $sentinel = User::factory()->create();
        $company = LeadCompany::factory()->create();
        $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);
        $analysis = Analysis::factory()->for($project)->create(['created_by' => $sentinel->id]);
        $website = Website::factory()->for($project)->create();

        return WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
    }

    public function test_has_crawl_data_is_false_when_no_pages_exist(): void
    {
        $wa = $this->makeWebsiteAnalysis();

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa);

        $this->assertFalse($summary['has_crawl_data']);
        $this->assertSame([], $summary['warnings']);
    }

    public function test_counts_are_grouped_correctly_by_status(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        AnalysisCrawledPage::factory()->count(5)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED]);
        AnalysisCrawledPage::factory()->count(2)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FAILED]);
        AnalysisCrawledPage::factory()->count(1)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_EXCLUDED_BY_TRACK]);
        AnalysisCrawledPage::factory()->count(3)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_PENDING]);
        AnalysisCrawledPage::factory()->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED, 'rendered_html_path' => 'x.html']);

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa);

        $this->assertTrue($summary['has_crawl_data']);
        $this->assertSame(6, $summary['fetched_count']);
        $this->assertSame(2, $summary['failed_count']);
        $this->assertSame(3, $summary['pending_count']);
        $this->assertSame(1, $summary['excluded_counts']['by_track']);
        $this->assertSame(0, $summary['excluded_counts']['by_pattern']);
        $this->assertSame(1, $summary['rendered_count']);
    }

    public function test_unknown_or_null_reason_maps_to_the_configured_unknown_label(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        AnalysisCrawledPage::factory()->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED]);

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa);

        $this->assertNull($summary['finished_reason']);
        $this->assertSame(config('crawl_diagnostics.unknown_finished_reason_label'), $summary['finished_reason_label']);
    }

    public function test_duration_is_computed_from_earliest_page_to_finished_at(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        $startedAt = now()->subMinutes(10);
        AnalysisCrawledPage::factory()->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED, 'created_at' => $startedAt]);
        $finishedAt = now();
        $wa->update(['crawl_finished_reason' => 'exhausted', 'crawl_finished_at' => $finishedAt]);

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa->fresh());

        $this->assertEqualsWithDelta(600, $summary['duration_seconds'], 2);
    }

    public function test_failed_urls_are_capped_at_the_configured_limit(): void
    {
        config(['crawl_diagnostics.failed_url_display_limit' => 3]);
        $wa = $this->makeWebsiteAnalysis();
        for ($i = 0; $i < 5; $i++) {
            AnalysisCrawledPage::factory()->create([
                'website_analysis_id' => $wa->id,
                'status' => AnalysisCrawledPage::STATUS_FAILED,
                'url' => "https://example.com/f{$i}",
                'http_status' => 500,
            ]);
        }

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa);

        $this->assertCount(3, $summary['failed_urls']);
        $this->assertSame(2, $summary['failed_urls_overflow_count']);
        $this->assertSame(500, $summary['failed_urls'][0]['http_status']);
    }

    public function test_low_fetched_page_count_warning_uses_the_configured_threshold(): void
    {
        config(['crawl_diagnostics.low_fetched_page_count_threshold' => 10]);
        $wa = $this->makeWebsiteAnalysis();
        AnalysisCrawledPage::factory()->count(9)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED]);

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa);

        $this->assertContains('low_fetched_page_count', array_column($summary['warnings'], 'key'));
    }

    public function test_high_failure_rate_warning_uses_the_configured_threshold(): void
    {
        config(['crawl_diagnostics.high_failure_rate_threshold' => 0.3]);
        $wa = $this->makeWebsiteAnalysis();
        AnalysisCrawledPage::factory()->count(20)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED]);
        AnalysisCrawledPage::factory()->count(9)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FAILED]);

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa);

        $this->assertContains('high_failure_rate', array_column($summary['warnings'], 'key'));
    }

    public function test_total_timeout_reason_always_warns_regardless_of_counts(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        AnalysisCrawledPage::factory()->count(40)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED]);
        $wa->update(['crawl_finished_reason' => 'total_timeout', 'crawl_finished_at' => now()]);

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa->fresh());

        $this->assertContains('total_timeout', array_column($summary['warnings'], 'key'));
    }
}
