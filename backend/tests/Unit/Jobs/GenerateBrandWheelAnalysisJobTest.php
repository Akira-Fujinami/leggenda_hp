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
use App\Services\BrandWheel\OpenAiBrandWheelAnalysisProvider;
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

    /**
     * putHomepageHtml()は常にAnalysisPageをcreate()するため、makeLeadOwned
     * WebsiteAnalysis()が既に作成済みの行に対しては使えない(unique制約
     * website_analysis_id+page_typeに違反する)。本文だけを差し替えたい
     * テスト(2026-08-25追加、複数の下位要素に別々のevidenceを割り当てる
     * ため)向けに、既存のraw_html_pathへ上書きするだけのヘルパー。
     */
    private function overwriteHomepageHtml(WebsiteAnalysis $websiteAnalysis, string $bodyText): void
    {
        $html = '<html><head><title>Example</title></head><body><p>'.$bodyText.'</p></body></html>';
        $path = app(AnalysisStoragePaths::class)->rawHtmlPath($websiteAnalysis->analysis_id, $websiteAnalysis->id, 'homepage.html');
        Storage::disk('analysis')->put($path, $html);
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
                        'impression' => [],
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
        $this->assertSame(OpenAiBrandWheelAnalysisProvider::PROMPT_VERSION, $record->prompt_version);
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

        // 依頼P-1: success(285行目)は$inputが存在する終端経路なので、
        // input_char_countも書かれる。
        $this->assertNotNull($record->input_char_count);
        $this->assertGreaterThan(0, $record->input_char_count);
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
        // 依頼P-1: AI呼び出し失敗(276行目)は$inputが存在する終端経路なので、
        // input_char_countも書かれる。
        $this->assertNotNull($record->input_char_count);
        $this->assertGreaterThan(0, $record->input_char_count);
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
        // 依頼P-1: provider未設定エラー(206行目)は$inputが存在する終端経路
        // なので、input_char_countも書かれる。
        $this->assertNotNull($record->input_char_count);
        $this->assertGreaterThan(0, $record->input_char_count);
    }

    /**
     * 依頼P-1: 入力の組み立て自体が失敗した場合(131行目、$inputが存在
     * しない)は、input_char_countを算出しようがないためnullのまま
     * (既存行に対する遡及計算はしない、というマイグレーションの方針と
     * 同じ考え方)。
     */
    public function test_input_char_count_stays_null_when_input_assembly_fails(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        $failingFactory = \Mockery::mock(BrandWheelAnalysisInputFactory::class);
        $failingFactory->shouldReceive('build')->once()->andThrow(new \RuntimeException('simulated input assembly failure'));

        (new GenerateBrandWheelAnalysisJob($record->id))->handle($failingFactory, app(AnalysisPipeline::class));

        $record->refresh();
        $this->assertSame('error', $record->status);
        $this->assertSame('BRAND_WHEEL_INPUT_BUILD_FAILED', $record->error_code);
        $this->assertNull($record->input_char_count);
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
                    'impression' => [],
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
        // 依頼P-1最重要: キャッシュ再利用(228行目)でinput_char_countが
        // 落ちていないこと。usage_input_tokensは再利用時に0固定になり
        // 判定材料として使えない(依頼Oで判明)ため、input_char_countは
        // ここで確実に書かれている必要がある。
        $this->assertNotNull($second->input_char_count);
        $this->assertGreaterThan(0, $second->input_char_count);
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
                    'impression' => [],
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
                    'impression' => [],
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
        // 依頼P-1: insufficient_input(163行目)は$inputが存在する終端経路
        // なので、input_char_countも書かれる(nullのままにならない)。
        $this->assertNotNull($record->input_char_count);
        $this->assertGreaterThan(0, $record->input_char_count);
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

    /**
     * 2026-08-19追加: analysis_id=45/website_analysis_id=93の障害調査用。
     * fetch_recruit_page/render_pageが完了扱いなのにsource_pagesが
     * unreadableになるケースを本番ログから検知できるよう、Job開始時の
     * ストレージ診断ログ(hostname・disk root・各パスのexists()結果)と、
     * source_pagesにunreadableが含まれる場合の警告ログが、両方とも
     * このJobのcompleted扱い(insufficient_input)とは独立して必ず出る
     * ことを確認する。
     */
    public function test_logs_storage_diagnostics_and_a_warning_when_a_source_page_is_unreadable(): void
    {
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
        // Storageへは意図的に何も書き込まない(analysis_id=45/website_analysis_id=93と
        // 同じ「DB上はパスがあるのに実ファイルが読めない」状態を再現する)。

        Log::spy();

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($websiteAnalysis, $missingPath) {
                return $message === 'Brand wheel analysis: storage diagnostics at job start'
                    && $context['website_analysis_id'] === $websiteAnalysis->id
                    && $context['analysis_id'] === $websiteAnalysis->analysis_id
                    && $context['homepage_raw_path'] === $missingPath
                    && $context['homepage_raw_exists'] === false
                    && array_key_exists('hostname', $context)
                    && array_key_exists('analysis_disk_root', $context);
            })
            ->once();

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($websiteAnalysis) {
                return $message === 'Brand wheel analysis: a source page is unreadable despite the fetch/render jobs having completed'
                    && $context['website_analysis_id'] === $websiteAnalysis->id
                    && $context['source_pages']['home_page'] === 'unreadable'
                    && array_key_exists('hostname', $context);
            })
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
     * #B-2: リード診断の実行回数消費(LeadSession.analyses_used)は、診断開始時
     * ではなく自社サイトの本文取得成功が確定するこの時点で行われる
     * (LeadAnalysisController::store()側の検証はLeadAnalysisTestを参照)。
     *
     * @return array{website_analysis: WebsiteAnalysis, lead_session: LeadSession}
     */
    private function makeLeadOwnedWebsiteAnalysis(bool $isPrimary = true, int $homepageHttpStatus = 200): array
    {
        $leadSession = LeadSession::factory()->create(['analyses_used' => 0]);
        $project = Project::factory()->create(['lead_session_id' => $leadSession->id]);
        $website = Website::factory()->for($project)->create(['is_primary' => $isPrimary]);
        $analysis = Analysis::factory()->for($project)->completed()->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        $this->putHomepageHtml($websiteAnalysis, str_repeat('会社の紹介文です。', 30));
        AnalysisPage::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('page_type', PageType::Homepage)
            ->update(['http_status' => $homepageHttpStatus]);

        return ['website_analysis' => $websiteAnalysis, 'lead_session' => $leadSession];
    }

    /**
     * 2026-08-24: 消費の基準がstatus='success'かつmatched件数が
     * config('brand_wheel.report_eligibility_min_matched')(2026-08-25に
     * 1件以上→6件以上へ引き上げ、依頼A)以上へ変更された
     * (BrandWheelReportEligibility参照)。MockBrandWheelAnalysisProviderは
     * 設計上matched_sub_elementsを常に空で返す(実際には何も読んでいないことを
     * 明示するため)ため、この確認にはopenaiプロバイダと、実際にトップページ
     * 本文に存在する抜粋を使う。
     */
    public function test_lead_quota_is_consumed_once_self_site_input_is_sufficient_and_reachable(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        ['website_analysis' => $websiteAnalysis, 'lead_session' => $leadSession] = $this->makeLeadOwnedWebsiteAnalysis();
        // deduplicateEvidenceAcrossAxes()が同一evidence文字列を複数の下位要素
        // にわたって使い回している場合に1件だけ残し他をduplicate_evidenceとして
        // 破棄するため(BrandWheelAnalysisResponseParser)、6件それぞれに本文中の
        // 別々の文を割り当てる。
        $this->overwriteHomepageHtml($websiteAnalysis, str_repeat(
            '技術で社会に貢献するという目標を掲げています。複数の事業領域で商品を展開しています。'.
            '新しいプロジェクトに継続的に取り組んでいます。地域社会への貢献活動を行っています。'.
            '業界内で高い知名度を持っています。独自の技術力を強みとしています。',
            5,
        ));

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'sub_elements' => $this->completeSubElements([
                            'purpose' => ['matched' => true, 'evidence' => '技術で社会に貢献するという目標を掲げています。'],
                            'business_expansion' => ['matched' => true, 'evidence' => '複数の事業領域で商品を展開しています。'],
                            'project_initiative' => ['matched' => true, 'evidence' => '新しいプロジェクトに継続的に取り組んでいます。'],
                            'social_contribution' => ['matched' => true, 'evidence' => '地域社会への貢献活動を行っています。'],
                            'brand_recognition' => ['matched' => true, 'evidence' => '業界内で高い知名度を持っています。'],
                            'competitiveness' => ['matched' => true, 'evidence' => '独自の技術力を強みとしています。'],
                        ]),
                        'core_value' => ['readable' => false, 'evidence' => null],
                        'key_message' => null,
                        'impression' => [],
                        'quality_notes' => [],
                        'cautions' => [],
                    ])]],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $this->assertSame('success', $record->fresh()->status);
        $this->assertSame(1, $leadSession->fresh()->analyses_used);
        $this->assertNotNull(Analysis::find($websiteAnalysis->analysis_id)->lead_quota_consumed_at);
    }

    /**
     * 2026-08-24追加: status='success'でも24項目すべてmatched=0
     * (no_matched_content、白紙と同列)の場合は消費しない。
     */
    public function test_lead_quota_is_not_consumed_when_success_has_zero_matched_sub_elements(): void
    {
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);
        ['website_analysis' => $websiteAnalysis, 'lead_session' => $leadSession] = $this->makeLeadOwnedWebsiteAnalysis();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $this->assertSame('success', $record->fresh()->status);
        $this->assertSame(0, $leadSession->fresh()->analyses_used);
        $this->assertNull(Analysis::find($websiteAnalysis->analysis_id)->lead_quota_consumed_at);
    }

    /**
     * 2026-08-25追加(依頼A): matched件数が1〜5件(旧基準では消費対象だったが
     * 新基準config('brand_wheel.report_eligibility_min_matched')=6未満)の
     * 場合はstatus='success'でも消費しない。2026-08-24発行のレポート33
     * (自社1/24)がこの区分にあたる。
     */
    public function test_lead_quota_is_not_consumed_when_matched_count_is_below_the_report_eligibility_threshold(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        ['website_analysis' => $websiteAnalysis, 'lead_session' => $leadSession] = $this->makeLeadOwnedWebsiteAnalysis();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'sub_elements' => $this->completeSubElements([
                            'purpose' => ['matched' => true, 'evidence' => '会社の紹介文です。'],
                        ]),
                        'core_value' => ['readable' => false, 'evidence' => null],
                        'key_message' => null,
                        'impression' => [],
                        'quality_notes' => [],
                        'cautions' => [],
                    ])]],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $this->assertSame('success', $record->fresh()->status);
        $this->assertSame(1, collect((array) $record->fresh()->axes)->sum(fn (array $axis) => count($axis['matched_sub_elements'] ?? [])));
        $this->assertSame(0, $leadSession->fresh()->analyses_used);
        $this->assertNull(Analysis::find($websiteAnalysis->analysis_id)->lead_quota_consumed_at);
    }

    public function test_lead_quota_is_not_consumed_when_input_is_insufficient(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => []], 200)]);

        $leadSession = LeadSession::factory()->create(['analyses_used' => 0]);
        $project = Project::factory()->create(['lead_session_id' => $leadSession->id]);
        $website = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->completed()->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
        // AnalysisPage自体を作らない(=正常系の入力不足)。

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $this->assertSame('insufficient_input', $record->fresh()->status);
        $this->assertSame(0, $leadSession->fresh()->analyses_used);
    }

    public function test_lead_quota_is_not_consumed_for_the_competitor_website(): void
    {
        // is_primary=falseの比較サイト側でinput十分でも消費してはいけない
        // (依頼要件: 比較サイトのみが403でも診断は続行され、自社側の判定
        // だけが消費に影響する)。
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);
        ['website_analysis' => $websiteAnalysis, 'lead_session' => $leadSession] = $this->makeLeadOwnedWebsiteAnalysis(isPrimary: false);
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $this->assertSame('success', $record->fresh()->status);
        $this->assertSame(0, $leadSession->fresh()->analyses_used);
    }

    public function test_lead_quota_is_not_consumed_when_the_homepage_http_status_is_not_2xx(): void
    {
        // 内容の整ったエラーページ(4xx/5xxだが本文が長い)を誤って成功扱い
        // しないための確認のため、matched_sub_elementsが常に空のmockではなく
        // 実際にマッチが成立するopenaiフェイクを使う(2026-08-24)。
        // 文字数閾値・matched件数(2026-08-25の閾値引き上げ後も6件以上で
        // 満たす)を満たしていても、トップページのHTTPステータスが2xxで
        // なければ「本文取得成功」とはみなさない ―― HTTPステータス側の
        // ガートが単独で効いていることを、matched件数側の閾値未達で
        // たまたまfalseになる偽陽性と区別するため、matched件数は新閾値を
        // 満たす6件にしている。
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        ['website_analysis' => $websiteAnalysis, 'lead_session' => $leadSession] = $this->makeLeadOwnedWebsiteAnalysis(homepageHttpStatus: 403);
        // deduplicateEvidenceAcrossAxes()が同一evidence文字列の使い回しを
        // duplicate_evidenceとして破棄するため、6件それぞれに本文中の
        // 別々の文を割り当てる(このテスト自体の目的である「matched件数は
        // 新閾値を満たすが、HTTPステータスだけがブロックしている」を正確に
        // 再現するため)。
        $this->overwriteHomepageHtml($websiteAnalysis, str_repeat(
            '技術で社会に貢献するという目標を掲げています。複数の事業領域で商品を展開しています。'.
            '新しいプロジェクトに継続的に取り組んでいます。地域社会への貢献活動を行っています。'.
            '業界内で高い知名度を持っています。独自の技術力を強みとしています。',
            5,
        ));

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'sub_elements' => $this->completeSubElements([
                            'purpose' => ['matched' => true, 'evidence' => '技術で社会に貢献するという目標を掲げています。'],
                            'business_expansion' => ['matched' => true, 'evidence' => '複数の事業領域で商品を展開しています。'],
                            'project_initiative' => ['matched' => true, 'evidence' => '新しいプロジェクトに継続的に取り組んでいます。'],
                            'social_contribution' => ['matched' => true, 'evidence' => '地域社会への貢献活動を行っています。'],
                            'brand_recognition' => ['matched' => true, 'evidence' => '業界内で高い知名度を持っています。'],
                            'competitiveness' => ['matched' => true, 'evidence' => '独自の技術力を強みとしています。'],
                        ]),
                        'core_value' => ['readable' => false, 'evidence' => null],
                        'key_message' => null,
                        'impression' => [],
                        'quality_notes' => [],
                        'cautions' => [],
                    ])]],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $this->assertSame('success', $record->fresh()->status);
        $this->assertGreaterThan(0, collect((array) $record->fresh()->axes)->sum(fn (array $axis) => count($axis['matched_sub_elements'] ?? [])));
        $this->assertSame(0, $leadSession->fresh()->analyses_used);
    }

    public function test_lead_quota_is_untouched_for_a_non_lead_analysis(): void
    {
        // project.lead_session_idが無い(社内向けの通常診断)場合は一切触れない。
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);
        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $this->assertSame('success', $record->fresh()->status);
        $this->assertNull(Analysis::find($websiteAnalysis->analysis_id)->lead_quota_consumed_at);
        $this->assertSame(0, LeadSession::query()->count());
    }

    /**
     * GenerateBrandWheelAnalysisJobはAI呼び出しのレート制限等でリトライされる
     * ことがあり、そのたびにmaybeConsumeLeadQuota()も再実行される。
     * Analysis.lead_quota_consumed_atへの条件付きUPDATEで二重消費が防がれる
     * ことを、privateメソッドを直接2回呼び出して確認する(リトライのタイミングを
     * 正確に再現するより、ガードそのものを直接検証する方が確実なため)。
     */
    public function test_lead_quota_consumption_is_idempotent_across_job_retries(): void
    {
        ['website_analysis' => $websiteAnalysis, 'lead_session' => $leadSession] = $this->makeLeadOwnedWebsiteAnalysis();
        $reportableRecord = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'success',
            // 2026-08-25: report_eligibility_min_matched(既定6)を満たす件数
            // にする ―― 1件のままだと閾値未満でisReportable()がfalseになり、
            // このテストが検証したい冪等性(2回呼んでも1回しか消費されない)の
            // 前段(そもそも消費対象か)で早期returnしてしまう。
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [
                ['key' => 'purpose', 'evidence' => '会社の紹介文です。'],
                ['key' => 'business_expansion', 'evidence' => '会社の紹介文です。'],
                ['key' => 'project_initiative', 'evidence' => '会社の紹介文です。'],
                ['key' => 'social_contribution', 'evidence' => '会社の紹介文です。'],
            ]], ['axis_key' => 'asset', 'matched_sub_elements' => [
                ['key' => 'brand_recognition', 'evidence' => '会社の紹介文です。'],
                ['key' => 'competitiveness', 'evidence' => '会社の紹介文です。'],
            ]]],
        ]);

        $job = new GenerateBrandWheelAnalysisJob(1);
        $method = new \ReflectionMethod($job, 'maybeConsumeLeadQuota');
        $method->setAccessible(true);

        $method->invoke($job, $websiteAnalysis, $reportableRecord);
        $method->invoke($job, $websiteAnalysis, $reportableRecord);

        $this->assertSame(1, $leadSession->fresh()->analyses_used);
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
                    'impression' => [],
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
                    'impression' => [],
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
                    'impression' => [],
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
        // dispatchしていない)ため、依頼N以降のProgressCalculatorは
        // 「行が存在する種別の重みの合計」で正規化する ―― 分母
        // (GenerateBrandWheelAnalysisの重みのみ)と分子が一致し、
        // 進捗カスケードがちょうど1回呼ばれていればWebsiteAnalysis.progress
        // は100になる。2回呼ばれていれば同じ値のまま(重み合算ではなく
        // 完了済みJob種別の重み合計を都度計算し直す設計のため)だが、
        // 「呼ばれていない」場合は0のまま変化しないため、この比較で
        // 「呼び忘れ」だけは確実に検出できる。
        $this->assertSame(100, $websiteAnalysis->fresh()->progress);
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
                    'impression' => [],
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
        // このJob単体しかanalysis_jobsに存在しないため、正規化後は100になる
        // (依頼N)。
        $this->assertSame(100, $websiteAnalysis->fresh()->progress);

        // 同じレコードに対する2回目の実行(キュー再配送を模す)。
        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));

        $this->assertSame(1, AnalysisJob::query()
            ->where('analysis_id', $websiteAnalysis->analysis_id)
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('job_type', JobType::GenerateBrandWheelAnalysis)
            ->count());
        $this->assertSame(100, $websiteAnalysis->fresh()->progress);
    }

    /**
     * 2026-08-24追加: 8/16〜17の本番障害(positive_impressionカラム欠落による
     * QueryExceptionが、failed()側で常にJobTimeout固定だったためJOB_TIMEOUTと
     * して記録され、AIタイムアウト設定の調査へミスリードされた)の再発防止。
     * failed()がQueryExceptionをSQLSTATEで分類し、brand_wheel_analysis_results.
     * error_code / analysis_jobs.error_codeの両方に正しく反映することを確認する。
     */
    public function test_failed_classifies_undefined_column_query_exception_as_schema_mismatch(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        $record = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);
        AnalysisJob::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => JobType::GenerateBrandWheelAnalysis,
            'status' => AnalysisJobStatus::Running,
        ]);

        $previous = new \PDOException('column "positive_impression" does not exist', '42703');
        $queryException = new \Illuminate\Database\QueryException('pgsql', 'update brand_wheel_analysis_results set positive_impression = ?', [], $previous);

        (new GenerateBrandWheelAnalysisJob($record->id))->failed($queryException);

        $this->assertSame('error', $record->fresh()->status);
        $this->assertSame(\App\Enums\AnalysisErrorCode::SchemaMismatch->value, $record->fresh()->error_code);

        $jobRecord = AnalysisJob::query()
            ->where('analysis_id', $websiteAnalysis->analysis_id)
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('job_type', JobType::GenerateBrandWheelAnalysis)
            ->first();

        $this->assertSame(AnalysisJobStatus::Failed, $jobRecord->status);
        $this->assertSame(\App\Enums\AnalysisErrorCode::SchemaMismatch->value, $jobRecord->error_code);
    }
}
