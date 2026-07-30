<?php

namespace Tests\Unit\Jobs;

use App\Enums\MetricResultStatus;
use App\Enums\PageType;
use App\Jobs\GenerateBrandWheelAnalysisJob;
use App\Mail\BrandWheelAnalysisCompletedMail;
use App\Mail\BrandWheelLeadAnalysisCompletedMail;
use App\Models\AnalysisPage;
use App\Models\Analysis;
use App\Models\LeadSession;
use App\Models\BrandWheelAnalysisResult;
use App\Models\CategoryDefinition;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\BrandWheel\BrandWheelAnalysisInputFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateBrandWheelAnalysisJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
    }

    /**
     * ほとんどのテストは「入力は十分にある」前提でプロバイダ・冪等性周りの
     * 挙動を検証するため、閾値(config('brand_wheel.insufficient_input_min_total_chars'))を
     * 明確に上回るホームページ本文を持つAnalysisPageを既定で用意する。
     * 入力不足の判定自体を検証するテストは、これを使わず個別にセットアップする。
     */
    private function makeWebsiteAnalysis(): WebsiteAnalysis
    {
        $project = Project::factory()->create();
        $website = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->completed()->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        $category = CategoryDefinition::factory()->create(['key' => 'technical_seo', 'weight' => 20]);
        $definition = MetricDefinition::factory()->create([
            'key' => 'title_present', 'category_key' => 'technical_seo', 'scoring_type' => 'boolean', 'max_score' => 10,
        ]);
        MetricResult::factory()->create([
            'website_analysis_id' => $websiteAnalysis->id, 'metric_definition_id' => $definition->id,
            'status' => MetricResultStatus::Success, 'normalized_value' => ['value' => true],
        ]);

        $this->putHomepageHtml($websiteAnalysis, str_repeat('会社の紹介文です。', 30));

        return $websiteAnalysis;
    }

    /**
     * リード企業向けメールの送信可否を検証するテスト専用。LeadSessionを
     * 紐づけたProjectでWebsiteAnalysisを用意する。
     *
     * @return array{0: WebsiteAnalysis, 1: LeadSession}
     */
    private function makeWebsiteAnalysisWithLeadSession(): array
    {
        $leadSession = LeadSession::factory()->create(['email' => 'lead@example.com']);
        $project = Project::factory()->create(['lead_session_id' => $leadSession->id]);
        $website = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->completed()->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        $this->putHomepageHtml($websiteAnalysis, str_repeat('会社の紹介文です。', 30));

        return [$websiteAnalysis, $leadSession];
    }

    private function putHomepageHtml(WebsiteAnalysis $websiteAnalysis, string $bodyText): void
    {
        $html = '<html><head><title>Example</title></head><body><p>'.$bodyText.'</p></body></html>';
        $path = app(AnalysisStoragePaths::class)->rawHtmlPath($websiteAnalysis->analysis_id, $websiteAnalysis->id, 'homepage.html');
        Storage::disk('analysis')->put($path, $html);

        AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com',
            'final_url' => 'https://example.com',
            'page_type' => PageType::Homepage,
            'http_status' => 200,
            'raw_html_path' => $path,
            'title' => 'Example',
            'fetched_at' => now(),
        ]);
    }

    public function test_timeout_is_always_ai_timeout_plus_thirty_seconds(): void
    {
        config(['services.brand_wheel_ai.timeout' => 45]);

        $job = new GenerateBrandWheelAnalysisJob(1);

        $this->assertSame(75, $job->timeout);
        $this->assertGreaterThan($job->timeout, $job->uniqueFor);
    }

    public function test_mock_provider_produces_a_successful_structured_result(): void
    {
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
            'is_mock' => false,
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('success', $record->status);
        $this->assertTrue($record->is_mock);
        $this->assertSame('mock', $record->provider);
        $this->assertCount(6, $record->axes);
        $this->assertSame(['read' => 0, 'partial' => 0, 'unread' => 6], $record->axis_state_counts);
        $this->assertNotEmpty($record->input_hash);
        $this->assertNotNull($record->generated_at);
    }

    public function test_openai_success_is_parsed_verified_and_stored_with_usage(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);

        $websiteAnalysis = $this->makeWebsiteAnalysis();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'axes' => [
                            'will_activity' => ['matched_sub_elements' => [
                                ['key' => 'purpose', 'evidence' => '架空の抜粋(実在しないので破棄されるはず)'],
                            ]],
                        ],
                        'core_value' => ['readable' => false],
                        'quality_notes' => [],
                        'cautions' => [],
                    ])]],
                ],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40],
            ], 200),
        ]);

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
            'is_mock' => false,
        ]);

        Log::spy();

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('success', $record->status);
        $this->assertFalse($record->is_mock);
        $this->assertSame('openai', $record->provider);
        $this->assertSame('v1', $record->prompt_version);
        $this->assertSame(120, $record->usage_input_tokens);
        $this->assertSame(40, $record->usage_output_tokens);
        // 実在しない抜粋は検証で破棄されるため、unreadのまま(AIの自己申告を信用しない)。
        $this->assertSame(['read' => 0, 'partial' => 0, 'unread' => 6], $record->axis_state_counts);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) {
                $bodyContainsSiteText = str_contains(json_encode($context), '架空の抜粋');

                return $message === 'Brand wheel analysis completed'
                    && $context['usage_input_tokens'] === 120
                    && ! $bodyContainsSiteText;
            })
            ->once();
    }

    public function test_openai_malformed_json_marks_result_as_error_without_failing_the_job(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'not valid json']]]], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('error', $record->status);
        $this->assertSame('AI_INVALID_JSON', $record->error_code);
    }

    public function test_openai_auth_failure_marks_result_as_error_without_failing_the_job(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);

        Http::fake(['api.openai.com/*' => Http::response(['error' => 'unauthorized'], 401)]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('error', $record->status);
        $this->assertSame('AI_AUTH_FAILED', $record->error_code);
    }

    public function test_provider_unset_marks_result_as_error_without_hanging(): void
    {
        config(['services.brand_wheel_ai.provider' => 'totally-unknown']);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('error', $record->status);
        $this->assertSame('BRAND_WHEEL_PROVIDER_INVALID', $record->error_code);
    }

    public function test_repeat_call_with_identical_input_reuses_prior_success_without_calling_the_api_again(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'axes' => [], 'core_value' => ['readable' => false], 'quality_notes' => [], 'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();

        $first = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);
        (new GenerateBrandWheelAnalysisJob($first->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $second = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);
        (new GenerateBrandWheelAnalysisJob($second->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        Http::assertSentCount(1);
        $second->refresh();
        $this->assertSame('success', $second->status);
    }

    public function test_repeat_call_does_not_reuse_prior_success_when_brand_wheel_config_changed(): void
    {
        // input_hashは入力テキストだけでなく、config('brand_wheel')の内容
        // (軸定義・教師データ・閾値)のフィンガープリントも含めて算出される
        // ―― config/brand_wheel.phpを変更した後、同一サイトに対して古い
        // 基準で生成した結果が再利用され続けないことを確認する(2026-07-29の指摘)。
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'axes' => [], 'core_value' => ['readable' => false], 'quality_notes' => [], 'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();

        $first = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);
        (new GenerateBrandWheelAnalysisJob($first->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        // config/brand_wheel.phpの内容が変わった状況を模す(閾値を変更)。
        config(['brand_wheel.state_thresholds.default' => ['partial' => 1, 'read' => 3]]);

        $second = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);
        (new GenerateBrandWheelAnalysisJob($second->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        Http::assertSentCount(2);
        $second->refresh();
        $this->assertSame('success', $second->status);
        $this->assertNotSame($first->fresh()->input_hash, $second->input_hash);
    }

    public function test_repeat_call_does_not_reuse_prior_success_when_prompt_version_changed(): void
    {
        // OpenAiBrandWheelAnalysisProvider::PROMPT_VERSIONを直接書き換えることは
        // できないため、mockプロバイダのpromptVersion()が常にnullであることを
        // 利用し、mock→openaiの切り替え(promptVersionがnull→'v1'に変わる)で
        // 再利用されないことを確認する。
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();

        $first = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);
        (new GenerateBrandWheelAnalysisJob($first->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'axes' => [], 'core_value' => ['readable' => false], 'quality_notes' => [], 'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $second = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);
        (new GenerateBrandWheelAnalysisJob($second->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        Http::assertSentCount(1);
        $second->refresh();
        $this->assertSame('success', $second->status);
        $this->assertSame('openai', $second->provider);
        $this->assertNotSame($first->fresh()->input_hash, $second->input_hash);
    }

    public function test_result_is_scoped_by_website_analysis_and_never_used_for_scoring(): void
    {
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        // ブランド・ホイール結果はmetric_resultsに一切書き込まれない(スコアへ影響しない)。
        $this->assertSame(1, MetricResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }

    public function test_stored_record_never_contains_lead_pii_columns(): void
    {
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('brand_wheel_analysis_results');
        foreach (['company_name', 'contact_name', 'phone', 'email', 'lead_session_id'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    public function test_insufficient_input_is_recorded_without_calling_the_provider_or_setting_axes(): void
    {
        // ホームページ/採用ページのAnalysisPage自体が存在しないケース(正常系:
        // 採用ページが検出されなかった等)。「6軸すべてunread」という体裁の
        // 整った結果ではなく、判定自体を持たないinsufficient_inputとして
        // 記録されることを確認する(2026-07-29の指摘)。
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => []], 200)]);

        $project = Project::factory()->create();
        $website = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->completed()->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('insufficient_input', $record->status);
        $this->assertNull($record->provider);
        $this->assertNull($record->axes);
        $this->assertNull($record->axis_state_counts);
        $this->assertNull($record->core_value_readable);
        $this->assertNotEmpty($record->input_hash);
        Http::assertNothingSent();
    }

    public function test_insufficient_input_below_configured_threshold_still_skips_the_provider(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        config(['brand_wheel.insufficient_input_min_total_chars' => 200]);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => []], 200)]);

        $project = Project::factory()->create();
        $website = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->completed()->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        // 閾値未満の短い本文(合計100文字程度)。
        $this->putHomepageHtml($websiteAnalysis, str_repeat('短い本文。', 10));

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('insufficient_input', $record->status);
        Http::assertNothingSent();
    }

    public function test_missing_html_file_is_logged_as_an_error_and_treated_as_insufficient_input(): void
    {
        // AnalysisPage行はあるが、Storage上のファイル実体が無い(欠損)ケース。
        // これはRenderでディスクが共有できていない場合の症状と一致するため、
        // 運用上検知されるべき障害としてLog::errorで記録されなければならない
        // (Log::warningでは不十分 ―― 2026-07-29の指摘)。
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => []], 200)]);

        $project = Project::factory()->create();
        $website = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->completed()->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        $missingPath = app(AnalysisStoragePaths::class)->rawHtmlPath($websiteAnalysis->analysis_id, $websiteAnalysis->id, 'homepage.html');
        AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com',
            'page_type' => PageType::Homepage,
            'http_status' => 200,
            'raw_html_path' => $missingPath,
            'fetched_at' => now(),
        ]);
        // Storageへは意図的に何も書き込まない(ファイル欠損を再現する)。

        Log::spy();

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('insufficient_input', $record->status);
        Http::assertNothingSent();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context) => $message === 'Brand wheel analysis: stored raw HTML file is missing'
                && $context['website_analysis_id'] === $websiteAnalysis->id)
            ->once();
    }

    public function test_sufficient_input_above_threshold_proceeds_to_call_the_provider(): void
    {
        // makeWebsiteAnalysis()の既定の本文(閾値を明確に上回る)で、
        // 通常通りプロバイダが呼ばれることを確認する(回帰確認)。
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('success', $record->status);
        $this->assertNotNull($record->axes);
    }

    public function test_completion_sends_the_staff_notification_and_marks_staff_notified_at(): void
    {
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true, 'lead.notification_to' => 'staff@example.com']);
        Mail::fake();

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('success', $record->status);
        $this->assertNotNull($record->staff_notified_at);
        Mail::assertSent(BrandWheelAnalysisCompletedMail::class, 1);
    }

    public function test_completion_notification_is_not_resent_when_already_marked_as_notified(): void
    {
        // staff_notified_atが既に設定済みの場合(過去の実行で送信済み)、
        // Jobのリトライ・キュー再処理で再送されない(2026-07-30の指摘)。
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true, 'lead.notification_to' => 'staff@example.com']);
        Mail::fake();

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
            'staff_notified_at' => now()->subMinute(),
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('success', $record->status);
        Mail::assertNothingSent();
    }

    public function test_insufficient_input_also_sends_the_staff_notification_once(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key', 'lead.notification_to' => 'staff@example.com']);
        Mail::fake();
        Http::fake(['api.openai.com/*' => Http::response(['choices' => []], 200)]);

        $project = Project::factory()->create();
        $website = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->completed()->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('insufficient_input', $record->status);
        $this->assertNotNull($record->staff_notified_at);
        Http::assertNothingSent();
        Mail::assertSent(BrandWheelAnalysisCompletedMail::class, fn (BrandWheelAnalysisCompletedMail $mail) => $mail->data['insufficientInput'] === true);
        // insufficient_inputはリード企業向けメールの送信対象外(canSend()=false)。
        Mail::assertNotSent(BrandWheelLeadAnalysisCompletedMail::class);
        $this->assertNull($record->lead_notified_at);
    }

    public function test_lead_notification_is_sent_independently_when_at_least_one_axis_has_real_evidence(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key', 'lead.notification_to' => 'staff@example.com']);
        Mail::fake();

        [$websiteAnalysis] = $this->makeWebsiteAnalysisWithLeadSession();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'axes' => [
                        'will_activity' => ['matched_sub_elements' => [
                            ['key' => 'purpose', 'evidence' => '会社の紹介文です'],
                        ]],
                    ],
                    'core_value' => ['readable' => false], 'quality_notes' => [], 'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('success', $record->status);
        $this->assertNotNull($record->staff_notified_at);
        $this->assertNotNull($record->lead_notified_at);

        Mail::assertSent(BrandWheelAnalysisCompletedMail::class, 1);
        Mail::assertSent(BrandWheelLeadAnalysisCompletedMail::class, function (BrandWheelLeadAnalysisCompletedMail $mail) {
            return $mail->hasTo('lead@example.com');
        });
    }

    public function test_lead_notification_is_not_sent_and_staff_email_is_still_sent_when_mock_provider_leaves_all_axes_unread(): void
    {
        // MockBrandWheelAnalysisProviderは常に全軸unreadを返す。「6軸すべて
        // 読み取れませんでした」を社外へ送ることは絶対にしないというルールが
        // 実際のJob経路でも守られることを確認する。
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true, 'lead.notification_to' => 'staff@example.com']);
        Mail::fake();

        [$websiteAnalysis] = $this->makeWebsiteAnalysisWithLeadSession();

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('success', $record->status);
        $this->assertNotNull($record->staff_notified_at);
        $this->assertNull($record->lead_notified_at);

        Mail::assertSent(BrandWheelAnalysisCompletedMail::class, 1);
        Mail::assertNotSent(BrandWheelLeadAnalysisCompletedMail::class);
    }

    public function test_lead_notification_failure_does_not_block_or_duplicate_the_staff_notification(): void
    {
        // LeadSessionにメールアドレスが無い(何らかの理由で失われた)場合、
        // リード企業向けメールだけが送信不可になり、社内スタッフ向けメールの
        // 送信・staff_notified_atの更新には一切影響しない。再実行しても
        // 社内向けは再送されない。
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key', 'lead.notification_to' => 'staff@example.com']);
        Mail::fake();

        [$websiteAnalysis, $leadSession] = $this->makeWebsiteAnalysisWithLeadSession();
        $leadSession->update(['email' => '']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'axes' => [
                        'will_activity' => ['matched_sub_elements' => [
                            ['key' => 'purpose', 'evidence' => '会社の紹介文です'],
                        ]],
                    ],
                    'core_value' => ['readable' => false], 'quality_notes' => [], 'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $record->refresh();
        $this->assertNotNull($record->staff_notified_at);
        $this->assertNull($record->lead_notified_at);
        Mail::assertSent(BrandWheelAnalysisCompletedMail::class, 1);
        Mail::assertNotSent(BrandWheelLeadAnalysisCompletedMail::class);
    }

    public function test_retrying_after_a_lead_send_failure_sends_only_the_lead_email_not_the_staff_email_again(): void
    {
        // staff_notified_atは既に設定済み(前回成功)、lead_notified_atはnull
        // (前回失敗)という状態を直接再現し、再実行でリード企業向けだけが
        // 送信され、社内スタッフ向けは再送されないことを確認する。
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key', 'lead.notification_to' => 'staff@example.com']);
        Mail::fake();

        [$websiteAnalysis, $leadSession] = $this->makeWebsiteAnalysisWithLeadSession();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'axes' => [
                        'will_activity' => ['matched_sub_elements' => [
                            ['key' => 'purpose', 'evidence' => '会社の紹介文です'],
                        ]],
                    ],
                    'core_value' => ['readable' => false], 'quality_notes' => [], 'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'success',
            'staff_notified_at' => now()->subMinute(),
            'lead_notified_at' => null,
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class));

        $record->refresh();
        $this->assertNotNull($record->lead_notified_at);
        Mail::assertNotSent(BrandWheelAnalysisCompletedMail::class);
        Mail::assertSent(BrandWheelLeadAnalysisCompletedMail::class, function (BrandWheelLeadAnalysisCompletedMail $mail) {
            return $mail->hasTo('lead@example.com');
        });
    }
}
