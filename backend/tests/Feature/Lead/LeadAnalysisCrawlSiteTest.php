<?php

namespace Tests\Feature\Lead;

use App\Jobs\Analysis\CrawlWebsiteJob;
use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\Analysis;
use App\Models\LeadSession;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 依頼L-1: LEAD_CRAWL_SITE(config('lead.crawl_site'))によるリード診断の
 * 巡回・条件付きレンダリング有効化。既定falseで既存挙動を一切変えないこと
 * (L-1)、有効時は自社・競合の両方が対象になること(L-2、自社のみを巡回する
 * 経路が存在しないこと)を検証する。
 */
class LeadAnalysisCrawlSiteTest extends TestCase
{
    use RefreshDatabase;

    private function issueToken(): string
    {
        $response = $this->postJson('/api/lead/onboarding', [
            'company_name' => '株式会社サンプル',
            'contact_name' => '山田太郎',
            'email' => 'lead@example.com',
            'privacy_policy_agreed' => true,
        ]);

        return $response->json('data.token');
    }

    public function test_analysis_crawl_site_is_false_by_default(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
        $token = $this->issueToken();

        $response = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com']);

        $response->assertCreated();
        $analysis = Analysis::find($response->json('data.analysis_id'));
        $this->assertFalse($analysis->crawl_site);
    }

    public function test_analysis_crawl_site_is_true_when_lead_crawl_site_config_is_enabled(): void
    {
        config(['lead.crawl_site' => true]);
        Queue::fake([StartAnalysisJob::class]);
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
        $token = $this->issueToken();

        $response = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com']);

        $response->assertCreated();
        $analysis = Analysis::find($response->json('data.analysis_id'));
        $this->assertTrue($analysis->crawl_site);
    }

    /**
     * 依頼L-2(妥協不可): crawl_site=trueのとき、自社だけでなく競合の
     * WebsiteAnalysisに対してもCrawlWebsiteJobがdispatchされること。
     * dispatchBrandWheelAnalysisIfDue()はwebsite_analysis_id単位で呼ばれ、
     * is_primaryによる分岐が存在しないため、Analysis.crawl_site(1件のみ)を
     * trueにするだけで両方が対象になる ―― この依頼で自社のみを巡回する
     * 特別な経路は一切追加していないことを確認する。
     */
    public function test_crawl_site_enabled_targets_both_self_and_competitor_website_analyses(): void
    {
        config(['lead.crawl_site' => true]);
        Queue::fake([StartAnalysisJob::class]);
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
        $token = $this->issueToken();

        $analysisId = $this->postJson("/api/lead/analyses?token={$token}", [
            'self_url' => 'https://example.com',
            'competitor_url' => 'https://www.iana.org',
        ])->json('data.analysis_id');

        $websiteAnalysisIds = WebsiteAnalysis::query()->where('analysis_id', $analysisId)->pluck('id');
        $this->assertCount(2, $websiteAnalysisIds);

        Queue::fake([CrawlWebsiteJob::class]);
        $pipeline = app(AnalysisPipeline::class);
        foreach ($websiteAnalysisIds as $websiteAnalysisId) {
            $pipeline->dispatchBrandWheelAnalysisIfDue($analysisId, $websiteAnalysisId);
        }

        foreach ($websiteAnalysisIds as $websiteAnalysisId) {
            Queue::assertPushed(CrawlWebsiteJob::class, fn ($job) => $job->websiteAnalysisId === $websiteAnalysisId);
        }
        Queue::assertPushed(CrawlWebsiteJob::class, 2);
    }

    public function test_lead_session_analysis_still_completes_with_crawl_site_config_untouched(): void
    {
        // config('lead.crawl_site')を一切上書きしない ―― 既定値のまま
        // LeadAnalysisTest既存の回帰(自社1件のみ登録)が保たれることの
        // 確認用サニティチェック。
        Queue::fake([StartAnalysisJob::class]);
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
        $token = $this->issueToken();

        $response = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com']);

        $response->assertCreated();
        $this->assertSame(0, LeadSession::first()->analyses_used);
    }
}
