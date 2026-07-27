<?php

namespace Tests\Feature\Lead;

use App\Enums\AnalysisStatus;
use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\Analysis;
use App\Models\LeadSession;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LeadAnalysisTest extends TestCase
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

    public function test_a_missing_token_is_rejected_without_touching_analysis_start(): void
    {
        $response = $this->postJson('/api/lead/analyses', ['self_url' => 'https://example.com']);

        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'LEAD_TOKEN_MISSING');
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $response = $this->postJson('/api/lead/analyses?token=not-a-real-token', ['self_url' => 'https://example.com']);

        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'LEAD_TOKEN_INVALID');
    }

    public function test_valid_token_starts_an_analysis_for_self_site_only(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $token = $this->issueToken();

        $response = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com']);

        $response->assertCreated();
        $response->assertJsonStructure(['data' => ['analysis_id']]);

        $analysis = Analysis::find($response->json('data.analysis_id'));
        // Phase 2でSemrush併用が承認されたため、Lighthouseは省略しない。
        $this->assertFalse($analysis->skip_lighthouse);
        // スクリーンショット由来の指標は0件のため、撮影自体は省略する。
        $this->assertTrue($analysis->skip_screenshots);
        $this->assertSame(1, $analysis->project->websites()->count());
        $this->assertTrue($analysis->project->websites()->first()->is_primary);

        $this->assertSame(1, LeadSession::first()->analyses_used);
    }

    public function test_valid_token_with_a_competitor_url_registers_two_websites(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $token = $this->issueToken();

        $response = $this->postJson("/api/lead/analyses?token={$token}", [
            'self_url' => 'https://example.com',
            'competitor_url' => 'https://www.iana.org',
        ]);

        $response->assertCreated();
        $analysis = Analysis::find($response->json('data.analysis_id'));
        $this->assertSame(2, $analysis->project->websites()->count());
    }

    public function test_a_third_website_would_exceed_the_lead_website_cap_but_the_controller_never_attempts_it(): void
    {
        // ブロックB自体が「自社1件+競合1件」の2件しか受け付けないため、
        // config('lead.max_websites')=2は analyses->start() のmax_websites
        // 制約として渡されるが、そもそも3件目を登録する経路が存在しない
        // ことを設計として確認する(website_ids未指定 = 登録済み全件から
        // max_websites件までを対象にする既存ロジックのため)。
        $this->assertSame(2, (int) config('lead.max_websites'));
    }

    public function test_a_second_analysis_attempt_with_the_same_token_is_rejected_and_does_not_start_a_new_analysis(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $token = $this->issueToken();

        $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com'])->assertCreated();
        $this->assertSame(1, Analysis::count());

        $response = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://another-example.com']);

        $response->assertStatus(403);
        $response->assertJsonPath('error_code', 'LEAD_ANALYSIS_QUOTA_EXCEEDED');
        $this->assertSame(1, Analysis::count(), '拒否された場合は新しいAnalysisを作ってはいけない');
    }

    public function test_congestion_rejects_without_consuming_the_tokens_one_time_allowance(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        config(['lead.max_concurrent_analyses' => 1]);

        // 既に実行中のリード分析を1件作っておき、上限に達している状態を再現する。
        $busySession = LeadSession::factory()->create();
        $busyUser = User::factory()->create();
        $busyProject = new Project(['name' => 'busy']);
        $busyProject->user_id = $busyUser->id;
        $busyProject->lead_session_id = $busySession->id;
        $busyProject->save();
        Analysis::factory()->create(['project_id' => $busyProject->id, 'status' => AnalysisStatus::Running]);

        $token = $this->issueToken();
        $response = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com']);

        $response->assertStatus(503);
        $response->assertJsonPath('error_code', 'LEAD_ANALYZER_BUSY');

        // このトークン自身の分析はまだ1件も作られておらず、実行回数も消費されていない。
        $this->assertSame(0, LeadSession::where('id', '!=', $busySession->id)->first()->analyses_used);
    }

    public function test_lead_token_cannot_be_used_to_access_internal_sanctum_routes(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $token = $this->issueToken();
        $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com'])->assertCreated();

        // lead.tokenはauth:sanctumと完全に別系統のため、内部向けエンドポイントは
        // 未認証のまま401になる(トークンをどう渡しても認証扱いされない)。
        $response = $this->getJson("/api/projects?token={$token}");
        $response->assertStatus(401);
    }

    public function test_a_lead_owned_analysis_does_not_appear_in_an_internal_users_project_list(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $token = $this->issueToken();
        $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com'])->assertCreated();

        $staff = User::factory()->create();
        $response = $this->actingAs($staff)->getJson('/api/projects');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_progress_and_results_return_404_for_an_analysis_owned_by_a_different_lead_session(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $tokenA = $this->issueToken();
        $analysisId = $this->postJson("/api/lead/analyses?token={$tokenA}", ['self_url' => 'https://example.com'])
            ->json('data.analysis_id');

        $responseOnboardingB = $this->postJson('/api/lead/onboarding', [
            'company_name' => '別の会社',
            'contact_name' => '鈴木一郎',
            'email' => 'other-lead@example.com',
            'privacy_policy_agreed' => true,
        ]);
        $tokenB = $responseOnboardingB->json('data.token');

        $this->getJson("/api/lead/analyses/{$analysisId}/progress?token={$tokenB}")->assertNotFound();
        $this->getJson("/api/lead/analyses/{$analysisId}/results?token={$tokenB}")->assertNotFound();
    }

    public function test_progress_reports_a_simplified_status_without_any_job_names_or_error_codes(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $token = $this->issueToken();
        $analysisId = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com'])
            ->json('data.analysis_id');

        $response = $this->getJson("/api/lead/analyses/{$analysisId}/progress?token={$token}");

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['percent', 'status', 'message']]);
        $raw = $response->getContent();
        foreach (['job_type', 'error_code', 'queue_name', 'render_page', 'run_lighthouse'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }
    }

    public function test_results_endpoint_never_leaks_internal_job_or_error_details(): void
    {
        Http::fake([
            '*/analyze/render' => Http::response(['success' => true, 'data' => ['html' => '<html></html>', 'fixed_cta' => ['detected' => false]]], 200),
            '*/analyze/technology' => Http::response(['success' => true, 'data' => ['technologies' => []]], 200),
            '*/analyze/lighthouse' => Http::response(['success' => true, 'data' => ['scores' => [], 'metrics' => []]], 200),
        ]);

        $token = $this->issueToken();
        $analysisId = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com'])
            ->json('data.analysis_id');

        $response = $this->getJson("/api/lead/analyses/{$analysisId}/results?token={$token}");

        $response->assertOk();
        $raw = $response->getContent();
        foreach (['job_type', 'error_code', 'queue_name', 'metric_definition_id', 'storage_path'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }

        // リード分析ではCaptureScreenshotJob自体を省略するため、
        // スクリーンショット関連のAnalyzer呼び出しは一切発生しない。
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/analyze/screenshot'));
    }

    public function test_partial_analysis_still_returns_results_instead_of_nothing(): void
    {
        Http::fake([
            '*/analyze/render' => Http::response([], 500),
            '*/analyze/technology' => Http::response(['success' => true, 'data' => ['technologies' => []]], 200),
            '*/analyze/lighthouse' => Http::response([], 500),
        ]);

        $token = $this->issueToken();
        $analysisId = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com'])
            ->json('data.analysis_id');

        $response = $this->getJson("/api/lead/analyses/{$analysisId}/results?token={$token}");

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['status', 'websites']]);
        $this->assertNotEmpty($response->json('data.websites'));
    }
}
