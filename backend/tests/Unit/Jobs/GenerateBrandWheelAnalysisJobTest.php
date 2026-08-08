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
use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Models\AnalysisJob;
use App\Services\Analysis\AnalysisPipeline;
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
     * 進捗カスケード(AnalysisPipeline::updateWebsiteAnalysisProgress()等)を
     * 検証するテスト専用。WebsiteAnalysisFactory::completed()はprogress=100・
     * status=Completedを直接セットするため使わない ―― status=Completed
     * (終端状態)だとupdateWebsiteAnalysisProgress()自身が「終端状態のため
     * 更新不要」として無条件にno-opする(AnalysisPipeline.php参照)ため、
     * 進捗が実際に更新されたかどうかを検証できなくなる。
     */
    private function makeFreshWebsiteAnalysisWithSufficientContent(): WebsiteAnalysis
    {
        $project = Project::factory()->create();
        $website = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        $this->putHomepageHtml($websiteAnalysis, str_repeat('会社の紹介文です。', 30));

        return $websiteAnalysis;
    }

    /**
     * 通知(2通目メール)の送信可否を検証するテスト専用。LeadSessionを
     * 紐づけたProjectでWebsiteAnalysisを用意する。
     *
     * 2026-08-03: 2通目メールはBrandWheelCompletionNotifierにより
     * consultation_requested_atが設定されていない限り送られなくなった
     * (相談ボタン起点のディスパッチ廃止に伴う設計変更 ―― 診断実行時に
     * 生成が完了しても、相談の意思表示が無ければ送らない)。既定
     * $consultationRequested=trueとし、「まだ相談していない」ケースを
     * 検証するテストだけ明示的にfalseを渡す。
     *
     * @return array{0: WebsiteAnalysis, 1: LeadSession}
     */
    private function makeWebsiteAnalysisWithLeadSession(bool $consultationRequested = true): array
    {
        $leadSession = LeadSession::factory()->create([
            'email' => 'lead@example.com',
            'consultation_requested_at' => $consultationRequested ? now() : null,
        ]);
        $project = Project::factory()->create(['lead_session_id' => $leadSession->id]);
        $website = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->completed()->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        $this->putHomepageHtml($websiteAnalysis, str_repeat('会社の紹介文です。', 30));

        return [$websiteAnalysis, $leadSession];
    }

    /**
     * input_hash再利用のテストは、同一website_analysis_idに対してこのJobを
     * 複数回handle()する(BrandWheelAnalysisInput::toArrayにwebsite_analysis_id
     * 自体が含まれるため、ハッシュ一致による再利用は同一WebsiteAnalysisへの
     * 再実行でのみ起こりうる ―― RunBrandWheelAnalysisCommandの--forceによる
     * 再実行がまさにこのシナリオ)。2026-08-03のAnalysisJob連携により
     * analysis_jobsは(analysis_id, website_analysis_id, job_type)単位の
     * 冪等キーを持つため、同一website_analysis_idへ2回目のhandle()を呼ぶ前に
     * 前回のAnalysisJob行を明示的にリセットする(RunBrandWheelAnalysisCommand
     * 自身も同じ理由で同じリセットを行う)。
     */
    private function resetBrandWheelAnalysisJobRecord(WebsiteAnalysis $websiteAnalysis): void
    {
        \App\Models\AnalysisJob::query()
            ->where('analysis_id', $websiteAnalysis->analysis_id)
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('job_type', \App\Enums\JobType::GenerateBrandWheelAnalysis)
            ->delete();
    }

    /**
     * config('brand_wheel.axes')の24キー全部をmatched:falseで埋めたsub_elements。
     * AIモックの応答は常に24キー全部を含めないとguardAgainstIncompleteSchema()
     * (欠落7個以上でAI_INCOMPLETE_SCHEMA)に引っかかる。
     *
     * @param  array<string, array{matched: bool, evidence?: string|null}>  $overrides
     * @return array<string, array{matched: bool, evidence: string|null}>
     */
    private function completeSubElements(array $overrides = []): array
    {
        $keys = [];
        foreach ((array) config('brand_wheel.axes', []) as $axis) {
            $keys = array_merge($keys, array_keys((array) $axis['sub_elements']));
        }

        $base = array_fill_keys($keys, ['matched' => false, 'evidence' => null]);

        foreach ($overrides as $key => $entry) {
            $base[$key] = array_merge(['matched' => false, 'evidence' => null], $entry);
        }

        return $base;
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

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $record->refresh();
        $this->assertSame('success', $record->status);
        $this->assertTrue($record->is_mock);
        $this->assertSame('mock', $record->provider);
        $this->assertCount(6, $record->axes);
        $this->assertSame(['read' => 0, 'partial' => 0, 'unread' => 6], $record->axis_state_counts);
        $this->assertNotEmpty($record->input_hash);
        $this->assertNotNull($record->generated_at);
        // 2026-08-03: key_message/impression(リード向け画面下部の紺帯用)。
        $this->assertNotNull($record->key_message);
        $this->assertNotNull($record->impression);
    }

    public function test_openai_success_is_parsed_verified_and_stored_with_usage(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);

        $websiteAnalysis = $this->makeWebsiteAnalysis();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'sub_elements' => $this->completeSubElements([
                            'purpose' => ['matched' => true, 'evidence' => '架空の抜粋(実在しないので破棄されるはず)'],
                        ]),
                        'core_value' => ['readable' => false, 'evidence' => null],
                        'key_message' => null,
                        'impression' => null,
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

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $record->refresh();
        $this->assertSame('success', $record->status);
        $this->assertFalse($record->is_mock);
        $this->assertSame('openai', $record->provider);
        $this->assertSame('v6', $record->prompt_version);
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

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

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

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

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

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

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
                    'sub_elements' => $this->completeSubElements(),
                    'core_value' => ['readable' => false, 'evidence' => null],
                    'key_message' => null,
                    'impression' => null,
                    'quality_notes' => [],
                    'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();

        $first = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);
        (new GenerateBrandWheelAnalysisJob($first->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $this->resetBrandWheelAnalysisJobRecord($websiteAnalysis);
        $second = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);
        (new GenerateBrandWheelAnalysisJob($second->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

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
                    'sub_elements' => $this->completeSubElements(),
                    'core_value' => ['readable' => false, 'evidence' => null],
                    'key_message' => null,
                    'impression' => null,
                    'quality_notes' => [],
                    'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();

        $first = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);
        (new GenerateBrandWheelAnalysisJob($first->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        // config/brand_wheel.phpの内容が変わった状況を模す(閾値を変更)。
        config(['brand_wheel.state_thresholds.default' => ['partial' => 1, 'read' => 3]]);

        $this->resetBrandWheelAnalysisJobRecord($websiteAnalysis);
        $second = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);
        (new GenerateBrandWheelAnalysisJob($second->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

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
        (new GenerateBrandWheelAnalysisJob($first->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'sub_elements' => $this->completeSubElements(),
                    'core_value' => ['readable' => false, 'evidence' => null],
                    'key_message' => null,
                    'impression' => null,
                    'quality_notes' => [],
                    'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $this->resetBrandWheelAnalysisJobRecord($websiteAnalysis);
        $second = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);
        (new GenerateBrandWheelAnalysisJob($second->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

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

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

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

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

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

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

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

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

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

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

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

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $record->refresh();
        $this->assertSame('success', $record->status);
        $this->assertNotNull($record->axes);
    }

    /**
     * 2026-08-04: JSで本文を描画するサイト(recruit.lifull.com/culture/、
     * hello-world.smarthr.co.jp/で実際に再現)は、静的HTML(raw_html_path)
     * だけでは本文が実質空になり、200文字のinsufficient_inputしきい値を
     * 必ず下回っていた。BrandWheelAnalysisInputFactoryがrendered_html_pathを
     * 優先するようになったことで、このJobを直接実行してもinsufficient_input
     * にならないことをエンドツーエンドで確認する。
     */
    public function test_does_not_become_insufficient_input_when_static_html_is_empty_but_rendered_html_has_content(): void
    {
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);

        $project = Project::factory()->create();
        $website = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->completed()->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        $paths = app(AnalysisStoragePaths::class);
        $staticPath = $paths->rawHtmlPath($analysis->id, $websiteAnalysis->id, 'homepage.html');
        $renderedPath = $paths->rawHtmlPath($analysis->id, $websiteAnalysis->id, 'homepage.rendered.html');
        // 静的HTMLはJS実行前のシェルのみ(本文が実質空、SPA的なサイトを模す)。
        Storage::disk('analysis')->put($staticPath, '<html><head><title>Example</title></head><body><div id="app"></div></body></html>');
        // レンダリング後HTMLには実際の本文がある。
        Storage::disk('analysis')->put($renderedPath, '<html><head><title>Example</title></head><body><p>'.str_repeat('会社の紹介文です。', 30).'</p></body></html>');

        AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com',
            'final_url' => 'https://example.com',
            'page_type' => PageType::Homepage,
            'http_status' => 200,
            'raw_html_path' => $staticPath,
            'rendered_html_path' => $renderedPath,
            'title' => 'Example',
            'fetched_at' => now(),
        ]);

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $record->refresh();
        $this->assertNotSame('insufficient_input', $record->status);
        $this->assertSame('success', $record->status);
    }

    public function test_completion_sends_the_staff_notification_and_marks_staff_notified_at(): void
    {
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true, 'lead.notification_to' => 'staff@example.com']);
        Mail::fake();

        [$websiteAnalysis] = $this->makeWebsiteAnalysisWithLeadSession();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $record->refresh();
        $this->assertSame('success', $record->status);
        $this->assertNotNull($record->staff_notified_at);
        Mail::assertSent(BrandWheelAnalysisCompletedMail::class, 1);
    }

    public function test_completion_does_not_notify_yet_when_consultation_has_not_been_requested(): void
    {
        // 2026-08-03: 診断実行時に生成が完了しても、まだ「相談したい」の
        // 意思表示(consultation_requested_at)が無ければ2通目メールは
        // 送らない ―― 送信のもう1つのきっかけ(相談ボタン押下時)を
        // LeadAnalysisControllerTest側で別途検証する。
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true, 'lead.notification_to' => 'staff@example.com']);
        Mail::fake();

        [$websiteAnalysis] = $this->makeWebsiteAnalysisWithLeadSession(consultationRequested: false);
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $record->refresh();
        $this->assertSame('success', $record->status);
        $this->assertNull($record->staff_notified_at);
        $this->assertNull($record->lead_notified_at);
        Mail::assertNothingSent();
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

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $record->refresh();
        $this->assertSame('success', $record->status);
        Mail::assertNothingSent();
    }

    public function test_insufficient_input_also_sends_the_staff_notification_once(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key', 'lead.notification_to' => 'staff@example.com']);
        Mail::fake();
        Http::fake(['api.openai.com/*' => Http::response(['choices' => []], 200)]);

        // insufficient_input判定(合計文字数閾値未満)を、相談済みのLeadSessionを
        // 持つサイトで再現する(2通目メールの送信対象になりうることの確認)。
        $leadSession = LeadSession::factory()->create(['email' => 'lead@example.com', 'consultation_requested_at' => now()]);
        $project = Project::factory()->create(['lead_session_id' => $leadSession->id]);
        $website = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->completed()->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
        $this->putHomepageHtml($websiteAnalysis, str_repeat('短い本文。', 10));

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

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
                    'sub_elements' => $this->completeSubElements([
                        'purpose' => ['matched' => true, 'evidence' => '会社の紹介文です'],
                    ]),
                    'core_value' => ['readable' => false, 'evidence' => null],
                    'key_message' => null,
                    'impression' => null,
                    'quality_notes' => [],
                    'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

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

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

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
                    'sub_elements' => $this->completeSubElements([
                        'purpose' => ['matched' => true, 'evidence' => '会社の紹介文です'],
                    ]),
                    'core_value' => ['readable' => false, 'evidence' => null],
                    'key_message' => null,
                    'impression' => null,
                    'quality_notes' => [],
                    'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

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
                    'sub_elements' => $this->completeSubElements([
                        'purpose' => ['matched' => true, 'evidence' => '会社の紹介文です'],
                    ]),
                    'core_value' => ['readable' => false, 'evidence' => null],
                    'key_message' => null,
                    'impression' => null,
                    'quality_notes' => [],
                    'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'success',
            'staff_notified_at' => now()->subMinute(),
            'lead_notified_at' => null,
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $record->refresh();
        $this->assertNotNull($record->lead_notified_at);
        Mail::assertNotSent(BrandWheelAnalysisCompletedMail::class);
        Mail::assertSent(BrandWheelLeadAnalysisCompletedMail::class, function (BrandWheelLeadAnalysisCompletedMail $mail) {
            return $mail->hasTo('lead@example.com');
        });
    }

    /**
     * #A-2/2026-08-03: markRunning()/markCompleted()/markFailed()を基底クラスに
     * 寄せず4つの終端経路に個別配置した配線こそが、2026-07-24〜25の障害の
     * 発生源だった箇所と同じ性質のコード(進捗カスケードの呼び忘れ・二重呼び
     * 出しがAnalysisJobの状態を壊す)であるため、4つの終端経路それぞれで
     * AnalysisJobが終端状態になり、進捗カスケードがちょうど1回呼ばれる
     * (=WebsiteAnalysis.progressがGenerateBrandWheelAnalysisの重み(10)だけ
     * 進む)ことを直接固定する。
     */
    private function assertAnalysisJobTerminalAndProgressAdvancedOnce(WebsiteAnalysis $websiteAnalysis, AnalysisJobStatus $expectedStatus): void
    {
        $jobRecord = AnalysisJob::query()
            ->where('analysis_id', $websiteAnalysis->analysis_id)
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('job_type', JobType::GenerateBrandWheelAnalysis)
            ->first();

        $this->assertNotNull($jobRecord, 'expected an AnalysisJob row for GenerateBrandWheelAnalysis');
        $this->assertSame($expectedStatus, $jobRecord->status);
        $this->assertTrue($jobRecord->status->isTerminal());

        // このJob単体しかanalysis_jobsに存在しない(他のJob種別は一切
        // dispatchしていない)ため、進捗カスケードがちょうど1回呼ばれていれば
        // WebsiteAnalysis.progressはGenerateBrandWheelAnalysisの重み(10)と
        // 正確に一致する。2回呼ばれていれば同じ値のまま(重み合算ではなく
        // 完了済みJob種別の重み合計を都度計算し直す設計のため)だが、
        // 「呼ばれていない」場合は0のまま変化しないため、この比較で
        // 「呼び忘れ」だけは確実に検出できる。
        $this->assertSame(JobType::GenerateBrandWheelAnalysis->weight(), $websiteAnalysis->fresh()->progress);
    }

    public function test_insufficient_input_marks_the_analysis_job_terminal_and_updates_progress_once(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => []], 200)]);

        $project = Project::factory()->create();
        $website = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $this->assertSame('insufficient_input', $record->fresh()->status);
        $this->assertAnalysisJobTerminalAndProgressAdvancedOnce($websiteAnalysis, AnalysisJobStatus::Completed);
    }

    public function test_reused_cache_hit_marks_the_analysis_job_terminal_and_updates_progress_once(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'sub_elements' => $this->completeSubElements(),
                    'core_value' => ['readable' => false, 'evidence' => null],
                    'key_message' => null,
                    'impression' => null,
                    'quality_notes' => [],
                    'cautions' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $websiteAnalysis = $this->makeFreshWebsiteAnalysisWithSufficientContent();

        $first = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);
        (new GenerateBrandWheelAnalysisJob($first->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $this->resetBrandWheelAnalysisJobRecord($websiteAnalysis);
        $second = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);
        (new GenerateBrandWheelAnalysisJob($second->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        Http::assertSentCount(1);
        $this->assertSame('success', $second->fresh()->status);
        $this->assertAnalysisJobTerminalAndProgressAdvancedOnce($websiteAnalysis, AnalysisJobStatus::Completed);
    }

    public function test_success_marks_the_analysis_job_terminal_and_updates_progress_once(): void
    {
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);

        $websiteAnalysis = $this->makeFreshWebsiteAnalysisWithSufficientContent();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $this->assertSame('success', $record->fresh()->status);
        $this->assertAnalysisJobTerminalAndProgressAdvancedOnce($websiteAnalysis, AnalysisJobStatus::Completed);
    }

    public function test_non_retryable_error_marks_the_analysis_job_terminal_and_updates_progress_once(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'unauthorized'], 401)]);

        $websiteAnalysis = $this->makeFreshWebsiteAnalysisWithSufficientContent();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $this->assertSame('error', $record->fresh()->status);
        // Failed扱いでも「完了扱い」として進捗には加算される(既存の
        // ProgressCalculatorの方針: 失敗したJobも進捗上は完了扱い)。
        $this->assertAnalysisJobTerminalAndProgressAdvancedOnce($websiteAnalysis, AnalysisJobStatus::Failed);
    }

    /**
     * リトライ対象エラー(429)でrelease()する経路では、まだ結果が確定して
     * いないため、AnalysisJobは終端状態にならず、進捗カスケードも呼ばれない
     * (=WebsiteAnalysis.progressは0のまま)。$this->job(InteractsWithQueue)が
     * nullの直接handle()呼び出しではrelease()の分岐条件
     * ($this->job !== null)自体が満たされないため、Laravelの
     * withFakeQueueInteractions()でJobインスタンスを差し込んで検証する。
     */
    public function test_retryable_error_release_does_not_mark_terminal_or_update_progress(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'rate limited'], 429)]);

        $websiteAnalysis = $this->makeFreshWebsiteAnalysisWithSufficientContent();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        $job = (new GenerateBrandWheelAnalysisJob($record->id))->withFakeQueueInteractions();
        $job->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $job->assertReleased();
        $this->assertSame('pending', $record->fresh()->status);

        $jobRecord = AnalysisJob::query()
            ->where('analysis_id', $websiteAnalysis->analysis_id)
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('job_type', JobType::GenerateBrandWheelAnalysis)
            ->first();
        $this->assertNotNull($jobRecord);
        $this->assertSame(AnalysisJobStatus::Running, $jobRecord->status);
        $this->assertFalse($jobRecord->status->isTerminal());
        // 進捗カスケードが呼ばれていれば10(GenerateBrandWheelAnalysisの重み)に
        // なるはずだが、まだ結果未確定のため0のまま。
        $this->assertSame(0, $websiteAnalysis->fresh()->progress);
    }

    /**
     * 同一(analysis_id, website_analysis_id)に対してこのJobが2回
     * (例: キューの再配送)実行されても、2回目はmarkRunning()が既に終端の
     * AnalysisJobを検出してno-opになるため、進捗が二重に進むことは無い。
     */
    public function test_repeated_execution_for_the_same_website_analysis_does_not_double_count_progress(): void
    {
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);

        $websiteAnalysis = $this->makeFreshWebsiteAnalysisWithSufficientContent();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));
        $this->assertSame(JobType::GenerateBrandWheelAnalysis->weight(), $websiteAnalysis->fresh()->progress);

        // 同じレコードに対する2回目の実行(キュー再配送を模す)。
        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $this->assertSame(1, AnalysisJob::query()
            ->where('analysis_id', $websiteAnalysis->analysis_id)
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('job_type', JobType::GenerateBrandWheelAnalysis)
            ->count());
        $this->assertSame(JobType::GenerateBrandWheelAnalysis->weight(), $websiteAnalysis->fresh()->progress);
    }
}
