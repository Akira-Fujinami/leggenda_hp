<?php

namespace Tests\Feature\Lead;

use App\Enums\PageType;
use App\Models\Analysis;
use App\Models\AnalysisPage;
use App\Models\LeadSession;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Admin\CrawlDiagnosticsService;
use App\Services\Analysis\RobotsAccessClassifier;
use App\Services\Lead\CompetitorAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 依頼DR: 競合サイトが robots.txt の取得を拒否していた(403など)ときの、理由の出し分けと、診断の回数の戻し。
 */
class CompetitorAccessBlockedTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Analysis, 1: WebsiteAnalysis, 2: WebsiteAnalysis, 3: LeadSession} */
    private function leadAnalysis(?int $competitorRobotsStatus, string $reason = 'robots_txt_unavailable'): array
    {
        $session = LeadSession::factory()->create(['analyses_used' => 1]);
        $project = Project::factory()->create(['lead_session_id' => $session->id]);
        $analysis = Analysis::factory()->for($project)->create(['lead_quota_consumed_at' => now()]);
        $self = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => Website::factory()->for($project)->create(['is_primary' => true, 'url' => 'https://self.example.com/recruit/'])->id]);
        $rival = WebsiteAnalysis::factory()->create([
            'analysis_id' => $analysis->id,
            'website_id' => Website::factory()->for($project)->create(['is_primary' => false, 'url' => 'https://rival.example.com/recruit/'])->id,
            'crawl_finished_reason' => $reason,
            'crawl_finished_at' => now(),
        ]);
        if ($competitorRobotsStatus !== null) {
            AnalysisPage::factory()->create(['website_analysis_id' => $rival->id, 'page_type' => PageType::Robots, 'url' => 'https://rival.example.com/robots.txt', 'http_status' => $competitorRobotsStatus]);
        }

        return [$analysis->fresh(), $self, $rival, $session];
    }

    public function test_the_reason_is_classified_by_the_status_of_the_robots_txt(): void
    {
        $classifier = app(RobotsAccessClassifier::class);

        $this->assertSame(['class' => 'blocked', 'http_status' => 403], $classifier->classify($this->leadAnalysis(403)[2]->fresh()));
        $this->assertSame('server_error', $classifier->classify($this->leadAnalysis(503)[2]->fresh())['class']);
        $this->assertSame('unreachable', $classifier->classify($this->leadAnalysis(null)[2]->fresh())['class']);
        $this->assertNull($classifier->classify($this->leadAnalysis(403, 'max_pages')[2]->fresh())['class'], 'robots.txt で止めていなければ理由は無い');
    }

    public function test_the_admin_hint_says_the_server_refuses_with_the_status_code(): void
    {
        [, , $rival] = $this->leadAnalysis(403);

        $hint = app(CrawlDiagnosticsService::class)->summarize($rival->fresh(), true)['critical_warning']['reason_hint'];

        $this->assertStringContainsString('アクセスを拒否しています', $hint);
        $this->assertStringContainsString('HTTP 403', $hint);
    }

    public function test_the_quota_is_given_back_once_when_the_competitor_blocked_the_crawler(): void
    {
        [$analysis, , , $session] = $this->leadAnalysis(403);
        $service = app(CompetitorAccessService::class);

        $this->assertTrue($service->competitorBlocked($analysis));
        $this->assertTrue($service->refundIfCompetitorBlocked($analysis));
        $this->assertSame(0, $session->fresh()->analyses_used);
        $this->assertNull($analysis->fresh()->lead_quota_consumed_at);

        $this->assertFalse($service->refundIfCompetitorBlocked($analysis->fresh()), '二重には戻さない');
        $this->assertSame(0, $session->fresh()->analyses_used);
    }

    public function test_the_quota_is_kept_when_the_competitor_did_not_block(): void
    {
        [$analysis, , , $session] = $this->leadAnalysis(503);

        $this->assertFalse(app(CompetitorAccessService::class)->refundIfCompetitorBlocked($analysis));
        $this->assertSame(1, $session->fresh()->analyses_used);
        $this->assertNotNull($analysis->fresh()->lead_quota_consumed_at);
    }
}
