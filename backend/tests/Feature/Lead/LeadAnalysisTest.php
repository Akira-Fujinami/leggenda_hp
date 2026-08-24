<?php

namespace Tests\Feature\Lead;

use App\Enums\AnalysisStatus;
use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\Analysis;
use App\Models\LeadSession;
use App\Models\Project;
use App\Models\User;
use App\Notifications\Lead\LeadAnalysisStartedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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
        Log::spy();

        $response = $this->postJson('/api/lead/analyses', ['self_url' => 'https://example.com']);

        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'LEAD_TOKEN_MISSING');
        // 2026-07-29対応: 画面は理由を区別しない統一文言、コピー時のURL欠落等の
        // 実運用インシデントを踏まえ「有効期限が切れています」と断定しない。
        $response->assertJsonPath('message', 'この診断URLは利用できません。お手数ですが、もう一度お申し込みください。');
        Log::shouldHaveReceived('info')->once()->with('Lead token validation failed: missing');
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        Log::spy();

        $response = $this->postJson('/api/lead/analyses?token=not-a-real-token', ['self_url' => 'https://example.com']);

        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'LEAD_TOKEN_INVALID');
        $response->assertJsonPath('message', 'この診断URLは利用できません。お手数ですが、もう一度お申し込みください。');
        // 画面向けメッセージは「該当なし」と「期限切れ」を区別しないが、ログは区別する
        // (LeadSessionServiceTokenValidationTestで検証)。トークン値は記録しない。
        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message) => $message === 'Lead token validation failed: not found');
    }

    public function test_valid_token_starts_an_analysis_for_self_site_only(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        // #B-1: store()がself_urlへ1回だけ到達性チェックを行う(SafeHttpFetcher
        // 経由)。StartAnalysisJobはfakeしているため、後続のパイプライン
        // (FetchStaticPageJob等)の実取得は発生しない。
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
        $token = $this->issueToken();

        $response = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com']);

        $response->assertCreated();
        $response->assertJsonStructure(['data' => ['analysis_id']]);

        $analysis = Analysis::find($response->json('data.analysis_id'));
        // 2026-08-18: 本番実測でLighthouseが1診断の所要時間の大半を占めることが
        // 判明したため、リード分析では省略する(既定true)。
        $this->assertTrue($analysis->skip_lighthouse);
        // スクリーンショット由来の指標は0件のため、撮影自体は省略する。
        $this->assertTrue($analysis->skip_screenshots);
        $this->assertSame(1, $analysis->project->websites()->count());
        $this->assertTrue($analysis->project->websites()->first()->is_primary);

        // 2026-08-22: 実行回数の消費は診断開始時ではなく、自社サイトの本文取得
        // 成功時点(GenerateBrandWheelAnalysisJob::maybeConsumeLeadQuota())へ
        // 後ろにずらした(#B-2)。StartAnalysisJobをfakeしているため後続の
        // パイプラインは走らず、この時点ではまだ消費されていない
        // (消費自体はGenerateBrandWheelAnalysisJobTestで検証する)。
        $this->assertSame(0, LeadSession::first()->analyses_used);
    }

    public function test_starting_an_analysis_sends_the_analysis_started_notification_when_a_recipient_is_configured(): void
    {
        config(['lead.notification_to' => 'staff@example.com']);
        Notification::fake();
        Queue::fake([StartAnalysisJob::class]);
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
        $token = $this->issueToken();

        $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com'])->assertCreated();

        Notification::assertSentOnDemand(LeadAnalysisStartedNotification::class);
    }

    public function test_starting_an_analysis_never_fails_the_request_when_no_notification_recipient_is_configured(): void
    {
        config(['lead.notification_to' => null]);
        Notification::fake();
        Queue::fake([StartAnalysisJob::class]);
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
        $token = $this->issueToken();

        $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com'])->assertCreated();

        Notification::assertNothingSent();
    }

    public function test_valid_token_with_a_competitor_url_registers_two_websites(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        // #B-1のチェック対象はself_urlのみ(competitor_urlは対象外)。
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
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
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
        $token = $this->issueToken();

        $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com'])->assertCreated();
        $this->assertSame(1, Analysis::count());

        // 2026-08-22: 実行回数の消費は自社サイトの本文取得成功時点
        // (GenerateBrandWheelAnalysisJob)へ後ろにずらした(#B-2)。ここでは
        // StartAnalysisJobをfakeしているためその消費は起きないので、
        // 「1回目の診断が完了し、消費も済んでいる」状態を直接再現してから
        // 2回目を試す(でなければhasAnalysisInProgress()の409で拒否され、
        // quota超過の経路を検証できない ―― その409はB-3の別テストで検証済み)。
        Analysis::first()->update(['status' => AnalysisStatus::Completed]);
        LeadSession::first()->update(['analyses_used' => 1]);

        Log::spy();
        $response = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://another-example.com']);

        $response->assertStatus(403);
        $response->assertJsonPath('error_code', 'LEAD_ANALYSIS_QUOTA_EXCEEDED');
        $this->assertSame(1, Analysis::count(), '拒否された場合は新しいAnalysisを作ってはいけない');

        // 2026-07-29対応: 「開けない」問い合わせの原因切り分け用ログ。トークン値は含めない。
        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context = []) use ($token) {
                return $message === 'Lead analysis start rejected: quota exceeded'
                    && isset($context['lead_session_id'])
                    && ! str_contains(json_encode($context), $token);
            });
    }

    /**
     * 診断回数の消費(recordAnalysisStarted())を診断開始直後ではなく後段
     * (自社サイトの本文取得成功時点)へ遅らせる変更に備えたガード
     * (LeadSessionService::hasAnalysisInProgress())。消費前でも、同一
     * トークンに実行中のAnalysisが既にある場合は新規受付を拒否する ――
     * ここではAnalysisを直接作って「まだ消費されていないが実行中」の
     * 状態を再現する(store()経由では現状すぐに消費されるため到達できない)。
     */
    public function test_a_second_analysis_attempt_while_one_is_still_in_progress_is_rejected(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        $token = $this->issueToken();

        $session = LeadSession::first();
        $user = User::factory()->create();
        $project = new Project(['name' => 'in-progress']);
        $project->user_id = $user->id;
        $project->lead_session_id = $session->id;
        $project->save();
        Analysis::factory()->create(['project_id' => $project->id, 'status' => AnalysisStatus::Running]);

        Log::spy();
        $response = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com']);

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'LEAD_ANALYSIS_IN_PROGRESS');
        $this->assertSame(1, Analysis::count(), '実行中の診断があるときは新しいAnalysisを作ってはいけない');
        $this->assertSame(0, $session->fresh()->analyses_used, '拒否された場合はトークンを消費してはいけない');

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context = []) use ($token) {
                return $message === 'Lead analysis start rejected: already in progress'
                    && isset($context['lead_session_id'])
                    && ! str_contains(json_encode($context), $token);
            });
    }

    /**
     * Worker停止・OOM等で終端処理に到達できず、statusがRunningのまま残った
     * 「停止した」Analysisは、config('lead.stale_analysis_after_minutes')
     * (既定30分)を過ぎたらhasAnalysisInProgress()の対象から除外され、
     * 同一トークンで新規に受け付けられる(依頼者指摘の恒久対応)。
     */
    public function test_a_stale_in_progress_analysis_does_not_block_a_new_attempt(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
        $token = $this->issueToken();

        $session = LeadSession::first();
        $user = User::factory()->create();
        $project = new Project(['name' => 'stale']);
        $project->user_id = $user->id;
        $project->lead_session_id = $session->id;
        $project->save();
        Analysis::factory()->create([
            'project_id' => $project->id,
            'status' => AnalysisStatus::Running,
            'created_at' => now()->subMinutes(31),
        ]);

        $response = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com']);

        $response->assertCreated();
        $this->assertSame(2, Analysis::count(), 'staleな行はそのまま残り、新しいAnalysisが作られる');
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

    public function test_a_stale_busy_analysis_does_not_count_toward_congestion(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
        config(['lead.max_concurrent_analyses' => 1]);

        $busySession = LeadSession::factory()->create();
        $busyUser = User::factory()->create();
        $busyProject = new Project(['name' => 'stale-busy']);
        $busyProject->user_id = $busyUser->id;
        $busyProject->lead_session_id = $busySession->id;
        $busyProject->save();
        Analysis::factory()->create([
            'project_id' => $busyProject->id,
            'status' => AnalysisStatus::Running,
            'created_at' => now()->subMinutes(31),
        ]);

        $token = $this->issueToken();
        $response = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com']);

        $response->assertCreated();
    }

    public function test_lead_token_cannot_be_used_to_access_internal_sanctum_routes(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
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
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
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
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
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
        Http::fake(['https://example.com' => Http::response('<html></html>', 200)]);
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
            // #B-1: store()がself_urlへ1回だけ到達性チェックを行う分。
            'https://example.com' => Http::response('<html></html>', 200),
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
            'https://example.com' => Http::response('<html></html>', 200),
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

    /**
     * Phase 3: 採用担当向けの4観点表示。既存の内部向けカテゴリ(technical_seo等)
     * を露出せず、①書くべきこと/②メッセージ/③導線/④見やすさの4件のみが
     * 返ること、および内部指標キー(metric_definitions.key)がラベルに
     * 混入していないことを確認する。
     */
    public function test_results_endpoint_returns_the_four_lead_perspectives_without_internal_category_names(): void
    {
        Http::fake([
            'https://example.com' => Http::response('<html></html>', 200),
            '*/analyze/render' => Http::response(['success' => true, 'data' => ['html' => '<html></html>', 'fixed_cta' => ['detected' => false]]], 200),
            '*/analyze/technology' => Http::response(['success' => true, 'data' => ['technologies' => []]], 200),
            '*/analyze/lighthouse' => Http::response(['success' => true, 'data' => ['scores' => [], 'metrics' => []]], 200),
        ]);

        $token = $this->issueToken();
        $analysisId = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com'])
            ->json('data.analysis_id');

        $response = $this->getJson("/api/lead/analyses/{$analysisId}/results?token={$token}");

        $response->assertOk();
        $perspectives = $response->json('data.websites.0.perspectives');

        $this->assertCount(4, $perspectives);
        $this->assertEqualsCanonicalizing(
            ['completeness', 'clarity', 'findability', 'usability'],
            array_column($perspectives, 'key'),
        );

        $raw = $response->getContent();
        // 内部カテゴリ名・指標キーが混入していないこと。
        foreach (['technical_seo', 'metric_definition', 'category_key', 'title_present', 'form_present'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }
    }

    /**
     * 2026-08-03: top_recommendationsはLeadRecommendationCatalogの許可リストを
     * 通す。トップページの静的HTML(title/meta description/フォームいずれも
     * 無い最小限のHTML)・robots.txt・sitemap.xml・技術検出の全てを
     * Http::fake()で明示的に固定し、実際のネットワーク通信を発生させずに
     * 検証する(TestCase::setUp()のHttp::preventStrayRequests()により、
     * ここで固定し忘れたURLへのリクエストがあれば例外でテストが失敗する)。
     *
     * 絞り込み前の候補には4観点に無いanalytics_configured
     * (「一般的なアクセス解析タグの検出」)も含まれるはずだが、レスポンスには
     * 一切出ないことを確認する。あわせて、出てくる文言にブランド・ホイールと
     * 同じ禁止語(不足/欠如/劣る/弱い/低い等)が含まれないことも確認する
     * (社外に出る文章のため)。
     */
    public function test_top_recommendations_excludes_off_catalog_metrics_and_forbidden_phrases(): void
    {
        // このテストクラスは既定でmetric_definitions/category_definitionsを
        // シードしない(他のテストは実測データに依存しないため)。この
        // テストだけは実際のMetricResult/Recommendationが記録されることに
        // 依存するため、明示的にシードする。
        $this->seed(\Database\Seeders\CategoryDefinitionSeeder::class);
        $this->seed(\Database\Seeders\MetricDefinitionSeeder::class);

        // title・meta description・フォームいずれも持たない最小限のHTML。
        // title_present/meta_description_present/form_present等、
        // LeadRecommendationCatalogに登録済みの複数キーで確実に改善提案が
        // 生成される(=フィルタが何も無い状態を素通りしているだけでないことの
        // 確認になる)。
        $minimalHtml = '<html><body><p>本文のみ</p></body></html>';

        Http::fake([
            'https://example.com' => Http::response($minimalHtml, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response('', 404),
            '*/analyze/render' => Http::response(['success' => true, 'data' => ['html' => $minimalHtml, 'fixed_cta' => ['detected' => false]]], 200),
            '*/analyze/technology' => Http::response(['success' => true, 'data' => ['technologies' => []]], 200),
            '*/analyze/lighthouse' => Http::response(['success' => true, 'data' => ['scores' => [], 'metrics' => []]], 200),
            // RunLighthouseJob::rejectIfAnalyzerBusy()の事前ヘルスチェック。
            // 固定し忘れるとHttp::preventStrayRequests()がStrayRequestException
            // (AnalyzerClient::healthDetails()がcatchしているConnectionException
            // とは型が違うため素通りする)を投げ、Jobがリトライで詰まる。
            '*/health' => Http::response(['success' => true, 'data' => ['active_contexts' => 0, 'queued_sessions' => 0, 'browser_connected' => true]], 200),
        ]);

        $token = $this->issueToken();
        $analysisId = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example.com'])
            ->json('data.analysis_id');

        $response = $this->getJson("/api/lead/analyses/{$analysisId}/results?token={$token}");

        $response->assertOk();
        $raw = $response->getContent();

        // 4観点に無いキー(analytics_configured)の技術用語は一切出ない。
        $this->assertStringNotContainsString('一般的なアクセス解析タグの検出', $raw);
        $this->assertStringNotContainsString('Largest Contentful Paint', $raw);

        $topRecommendations = $response->json('data.websites.0.top_recommendations');
        // フィルタが機能した上でなお何かしら出ていることを確認する
        // (0件だと以降のforeachが素通りし、検証が空振りになるため)。
        $this->assertNotEmpty($topRecommendations, 'expected at least one lead-facing recommendation from the minimal fixture HTML');

        $forbiddenPhrases = (array) config('brand_wheel.forbidden_phrases');
        $this->assertNotEmpty($forbiddenPhrases);

        foreach ($topRecommendations as $recommendation) {
            foreach ($forbiddenPhrases as $phrase) {
                $this->assertStringNotContainsString($phrase, $recommendation['title']);
                $this->assertStringNotContainsString($phrase, $recommendation['description']);
            }
        }
    }

    /**
     * 2026-08-03: ブランド・ホイール(6軸)は診断実行時に自社・競合の両方に
     * ついて生成される(相談ボタン起点のディスパッチは廃止)。Mockプロバイダ
     * (外部通信なし)で一連が通ること、レスポンスにリードの個人情報・
     * evidence(原文の抜粋)が一切含まれないことを実エンドポイント経由で確認する。
     */
    public function test_diagnosis_generates_brand_wheel_for_both_self_and_competitor_via_mock_provider(): void
    {
        config([
            'services.brand_wheel_ai.provider' => 'mock',
            'analysis.allow_mock_providers' => true,
        ]);

        $minimalHtml = '<html><body><p>本文のみ</p></body></html>';
        $fakes = [
            '*/analyze/render' => Http::response(['success' => true, 'data' => ['html' => $minimalHtml, 'fixed_cta' => ['detected' => false]]], 200),
            '*/analyze/technology' => Http::response(['success' => true, 'data' => ['technologies' => []]], 200),
            '*/analyze/lighthouse' => Http::response(['success' => true, 'data' => ['scores' => [], 'metrics' => []]], 200),
            '*/health' => Http::response(['success' => true, 'data' => ['active_contexts' => 0, 'queued_sessions' => 0, 'browser_connected' => true]], 200),
        ];
        foreach (['https://example.com', 'https://www.iana.org'] as $baseUrl) {
            $fakes[$baseUrl] = Http::response($minimalHtml, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            $fakes["{$baseUrl}/robots.txt"] = Http::response('', 404);
            $fakes["{$baseUrl}/sitemap.xml"] = Http::response('', 404);
        }
        Http::fake($fakes);

        $token = $this->issueToken();
        $analysisId = $this->postJson("/api/lead/analyses?token={$token}", [
            'self_url' => 'https://example.com',
            'competitor_url' => 'https://www.iana.org',
        ])->json('data.analysis_id');

        // ブランド・ホイールは自社・競合それぞれのWebsiteAnalysisに対して
        // 1件ずつ生成される(相談ボタンを一度も押していない)。
        $this->assertSame(2, \App\Models\BrandWheelAnalysisResult::query()->where('analysis_id', $analysisId)->count());

        $response = $this->getJson("/api/lead/analyses/{$analysisId}/results?token={$token}");
        $response->assertOk();

        $websites = $response->json('data.websites');
        $this->assertCount(2, $websites);

        foreach ($websites as $website) {
            $this->assertArrayHasKey('brand_wheel', $website);
            $this->assertContains(
                $website['brand_wheel']['status'],
                ['success', 'pending', 'insufficient_input', 'recruit_page_unreadable', 'no_matched_content', 'error'],
            );
        }

        $comparison = $response->json('data.brand_wheel_comparison');
        $this->assertArrayHasKey('self_points', $comparison);
        $this->assertArrayHasKey('competitor_points', $comparison);
        $this->assertArrayHasKey('one_point', $comparison);

        // リードの個人情報・evidence(原文の抜粋)がレスポンスに一切含まれない。
        $raw = $response->getContent();
        foreach (['山田太郎', '株式会社サンプル', 'lead@example.com', 'evidence', 'core_value_evidence'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }
    }
}
