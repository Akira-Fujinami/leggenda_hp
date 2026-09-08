<?php

namespace Tests\Feature\Lead;

use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\Analysis;
use App\Models\WebsiteAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 依頼BB-1/BB-3: 画面から送られたrecruitment_trackがanalysesに保存される
 * こと、送らなかった場合(「指定しない」)は既定値'unspecified'のままで
 * 現在の挙動を一切変えないことを検証する。LeadAnalysisCrawlSiteTestと
 * 同じ構成(1つの選択が自社・競合の両方に適用されること)に倣う。
 */
class LeadAnalysisRecruitmentTrackTest extends TestCase
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

    public function test_recruitment_track_defaults_to_unspecified_when_not_sent(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
        $token = $this->issueToken();

        $response = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com']);

        $response->assertCreated();
        $analysis = Analysis::find($response->json('data.analysis_id'));
        $this->assertSame('unspecified', $analysis->recruitment_track);
    }

    public function test_recruitment_track_is_saved_when_new_graduate_is_selected(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
        $token = $this->issueToken();

        $response = $this->postJson("/api/lead/analyses?token={$token}", [
            'self_url' => 'https://example.com',
            'recruitment_track' => 'new_graduate',
        ]);

        $response->assertCreated();
        $analysis = Analysis::find($response->json('data.analysis_id'));
        $this->assertSame('new_graduate', $analysis->recruitment_track);
    }

    public function test_recruitment_track_is_saved_when_career_is_selected(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
        $token = $this->issueToken();

        $response = $this->postJson("/api/lead/analyses?token={$token}", [
            'self_url' => 'https://example.com',
            'recruitment_track' => 'career',
        ]);

        $response->assertCreated();
        $analysis = Analysis::find($response->json('data.analysis_id'));
        $this->assertSame('career', $analysis->recruitment_track);
    }

    public function test_recruitment_track_rejects_unrecognized_values(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
        $token = $this->issueToken();

        $response = $this->postJson("/api/lead/analyses?token={$token}", [
            'self_url' => 'https://example.com',
            // 'unspecified'は画面から送らせない(DB既定値専用)。
            'recruitment_track' => 'unspecified',
        ]);

        $response->assertStatus(422);
    }

    /**
     * 1つの選択が自社・競合の両方に適用されること(自社が新卒・競合が
     * キャリアでは比較が成立しない、依頼BB-1の要件)。両WebsiteAnalysisが
     * 同じ1件のAnalysisにぶら下がる時点で、区分を分けて選ばせる経路自体が
     * 存在しないことを確認する。
     */
    public function test_one_selection_applies_to_both_self_and_competitor_websites(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
        $token = $this->issueToken();

        $analysisId = $this->postJson("/api/lead/analyses?token={$token}", [
            'self_url' => 'https://example.com',
            'competitor_url' => 'https://www.iana.org',
            'recruitment_track' => 'career',
        ])->json('data.analysis_id');

        $websiteAnalysisCount = WebsiteAnalysis::query()->where('analysis_id', $analysisId)->count();
        $this->assertSame(2, $websiteAnalysisCount);
        $this->assertSame('career', Analysis::find($analysisId)->recruitment_track);
    }
}
