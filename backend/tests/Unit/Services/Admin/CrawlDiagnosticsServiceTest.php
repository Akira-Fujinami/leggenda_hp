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

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa, true);

        $this->assertFalse($summary['has_crawl_data']);
        $this->assertSame([], $summary['warnings']);
    }

    /**
     * 依頼BV-3(この依頼の主目的): crawl_site=trueなのにこのサイトだけ
     * 巡回が1ページも行われなかった場合(LINEヤフーの実例)、BU-3の3条件
     * とは独立したcritical_warningを返す。
     */
    public function test_critical_warning_is_set_when_crawl_site_is_enabled_but_no_pages_were_crawled(): void
    {
        $wa = $this->makeWebsiteAnalysis();

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa, true);

        $this->assertNotNull($summary['critical_warning']);
        $this->assertSame('crawl_not_started', $summary['critical_warning']['key']);
        $this->assertSame(config('crawl_diagnostics.crawl_not_started_message'), $summary['critical_warning']['message']);
    }

    /**
     * crawl_site=false(機能自体を使っていない、多数派)の診断では、
     * 巡回0件は正常な状態のため、critical_warningを出さない。
     */
    public function test_critical_warning_is_null_when_crawl_site_is_disabled(): void
    {
        $wa = $this->makeWebsiteAnalysis();

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa, false);

        $this->assertNull($summary['critical_warning']);
    }

    /**
     * 巡回できたサイト(has_crawl_data=true)では、crawl_site=trueでも
     * critical_warningは出さない。
     */
    public function test_critical_warning_is_null_when_the_site_was_actually_crawled(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        AnalysisCrawledPage::factory()->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED]);

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa, true);

        $this->assertNull($summary['critical_warning']);
    }

    /**
     * 依頼BV-1: robots_txt_unavailable/no_allowed_hostsは、巡回0件でも
     * finished_reasonに値が入る(既存データのnullとは区別できる)。
     */
    public function test_robots_txt_unavailable_reason_is_shown_even_with_zero_crawled_pages(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        $wa->update(['crawl_finished_reason' => 'robots_txt_unavailable', 'crawl_finished_at' => now()]);

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa->fresh(), true);

        $this->assertFalse($summary['has_crawl_data']);
        $this->assertSame('robots_txt_unavailable', $summary['finished_reason']);
        $this->assertSame(config('crawl_diagnostics.finished_reason_labels.robots_txt_unavailable'), $summary['finished_reason_label']);
        $this->assertNotNull($summary['critical_warning']);
    }

    public function test_no_allowed_hosts_reason_is_shown_even_with_zero_crawled_pages(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        $wa->update(['crawl_finished_reason' => 'no_allowed_hosts', 'crawl_finished_at' => now()]);

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa->fresh(), true);

        $this->assertSame(config('crawl_diagnostics.finished_reason_labels.no_allowed_hosts'), $summary['finished_reason_label']);
    }

    /**
     * 依頼BV-2: 候補0件(render_candidate_count=0)は正常 ―― 静的HTMLで
     * 足りていたことを示すため、rendering_failed警告を出さない。
     */
    public function test_rendering_failed_warning_is_not_shown_when_there_were_zero_candidates(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        AnalysisCrawledPage::factory()->count(20)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED]);
        $wa->update(['render_candidate_count' => 0]);

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa->fresh(), true);

        $this->assertSame(0, $summary['render_candidate_count']);
        $this->assertNotContains('rendering_failed', array_column($summary['warnings'], 'key'));
    }

    /**
     * 依頼BV-2(主目的の一つ): 候補はあった(N>0)のに1枚も成功しなかった
     * (Analyzer混雑等でRenderCrawledPageJobが静的HTMLへ黙って降格した)
     * 場合は、候補0件(正常)と区別してrendering_failed警告を出す。
     */
    public function test_rendering_failed_warning_is_shown_when_candidates_existed_but_none_succeeded(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        AnalysisCrawledPage::factory()->count(20)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED]);
        $wa->update(['render_candidate_count' => 5]);
        // rendered_html_pathを持つ行が1件も無い = 成功0件。

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa->fresh(), true);

        $this->assertSame(5, $summary['render_candidate_count']);
        $this->assertSame(0, $summary['rendered_count']);
        $this->assertContains('rendering_failed', array_column($summary['warnings'], 'key'));
    }

    /**
     * 候補N件のうち一部でも成功していれば、rendering_failed警告は出さない。
     */
    public function test_rendering_failed_warning_is_not_shown_when_some_candidates_succeeded(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        AnalysisCrawledPage::factory()->count(20)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED]);
        AnalysisCrawledPage::factory()->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED, 'rendered_html_path' => 'x.html']);
        $wa->update(['render_candidate_count' => 5]);

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa->fresh(), true);

        $this->assertNotContains('rendering_failed', array_column($summary['warnings'], 'key'));
    }

    /**
     * render_candidate_countがnull(依頼BV適用前の既存データ)の場合は、
     * 候補あり/成功0を判定できないため、rendering_failed警告を出さない。
     */
    public function test_rendering_failed_warning_is_not_shown_when_candidate_count_is_null(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        AnalysisCrawledPage::factory()->count(20)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED]);
        // render_candidate_countは既定でnull。

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa->fresh(), true);

        $this->assertNull($summary['render_candidate_count']);
        $this->assertNotContains('rendering_failed', array_column($summary['warnings'], 'key'));
    }

    public function test_counts_are_grouped_correctly_by_status(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        AnalysisCrawledPage::factory()->count(5)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED]);
        AnalysisCrawledPage::factory()->count(2)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FAILED]);
        AnalysisCrawledPage::factory()->count(1)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_EXCLUDED_BY_TRACK]);
        AnalysisCrawledPage::factory()->count(3)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_PENDING]);
        AnalysisCrawledPage::factory()->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED, 'rendered_html_path' => 'x.html']);

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa, true);

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

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa, true);

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

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa->fresh(), true);

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

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa, true);

        $this->assertCount(3, $summary['failed_urls']);
        $this->assertSame(2, $summary['failed_urls_overflow_count']);
        $this->assertSame(500, $summary['failed_urls'][0]['http_status']);
    }

    public function test_low_fetched_page_count_warning_uses_the_configured_threshold(): void
    {
        config(['crawl_diagnostics.low_fetched_page_count_threshold' => 10]);
        $wa = $this->makeWebsiteAnalysis();
        AnalysisCrawledPage::factory()->count(9)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED]);

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa, true);

        $this->assertContains('low_fetched_page_count', array_column($summary['warnings'], 'key'));
    }

    public function test_high_failure_rate_warning_uses_the_configured_threshold(): void
    {
        config(['crawl_diagnostics.high_failure_rate_threshold' => 0.3]);
        $wa = $this->makeWebsiteAnalysis();
        AnalysisCrawledPage::factory()->count(20)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED]);
        AnalysisCrawledPage::factory()->count(9)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FAILED]);

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa, true);

        $this->assertContains('high_failure_rate', array_column($summary['warnings'], 'key'));
    }

    public function test_total_timeout_reason_always_warns_regardless_of_counts(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        AnalysisCrawledPage::factory()->count(40)->create(['website_analysis_id' => $wa->id, 'status' => AnalysisCrawledPage::STATUS_FETCHED]);
        $wa->update(['crawl_finished_reason' => 'total_timeout', 'crawl_finished_at' => now()]);

        $summary = app(CrawlDiagnosticsService::class)->summarize($wa->fresh(), true);

        $this->assertContains('total_timeout', array_column($summary['warnings'], 'key'));
    }
}
