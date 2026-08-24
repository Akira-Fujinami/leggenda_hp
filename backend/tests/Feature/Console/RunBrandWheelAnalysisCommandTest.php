<?php

namespace Tests\Feature\Console;

use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisStoragePaths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RunBrandWheelAnalysisCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
    }

    private function makeWebsiteAnalysis(): WebsiteAnalysis
    {
        $project = Project::factory()->create();
        $website = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->completed()->create();
        $websiteAnalysis = WebsiteAnalysis::factory()->completed()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        $html = '<html><head><title>Example</title></head><body><p>'.str_repeat('会社の紹介文です。', 30).'</p></body></html>';
        $path = app(AnalysisStoragePaths::class)->rawHtmlPath($analysis->id, $websiteAnalysis->id, 'homepage.html');
        Storage::disk('analysis')->put($path, $html);
        \App\Models\AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com',
            'final_url' => 'https://example.com',
            'page_type' => \App\Enums\PageType::Homepage,
            'http_status' => 200,
            'raw_html_path' => $path,
            'title' => 'Example',
            'fetched_at' => now(),
        ]);

        return $websiteAnalysis;
    }

    private function fakeOpenAiResponse(): void
    {
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

    public function test_it_refuses_to_run_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->artisan('brand-wheel:run', ['website_analysis_id' => 1])->assertFailed();
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }

        $this->assertSame(0, BrandWheelAnalysisResult::query()->count());
    }

    public function test_it_fails_cleanly_for_a_nonexistent_website_analysis(): void
    {
        $this->artisan('brand-wheel:run', ['website_analysis_id' => 999999])->assertFailed();
    }

    public function test_it_runs_the_analysis_synchronously_and_prints_the_result(): void
    {
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);
        $websiteAnalysis = $this->makeWebsiteAnalysis();

        $this->artisan('brand-wheel:run', ['website_analysis_id' => $websiteAnalysis->id])
            ->assertSuccessful();

        $record = BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertNotNull($record);
        $this->assertSame('success', $record->status);
    }

    public function test_without_force_a_second_run_reuses_the_prior_success_and_does_not_call_the_api_again(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        $this->fakeOpenAiResponse();
        $websiteAnalysis = $this->makeWebsiteAnalysis();

        $this->artisan('brand-wheel:run', ['website_analysis_id' => $websiteAnalysis->id])->assertSuccessful();
        $this->artisan('brand-wheel:run', ['website_analysis_id' => $websiteAnalysis->id])->assertSuccessful();

        // 2026-08-17: 1回目の実行で自社(単独)がsuccessに達した時点で、
        // BrandWheelImprovementSuggestionDispatcherが改善提案AIを1回だけ
        // 追加でdispatchする(QUEUE_CONNECTION=syncのためこのテスト内で同期
        // 実行される)。ただしこのfixtureの自社matched件数は1件のみ
        // (config('brand_wheel.comparison_sufficiency_threshold')既定6未満)
        // のため、2026-08-25の修正2により改善提案AIは呼ばれず(定型文の
        // one_pointのみ保存)、HTTPリクエストは増えない。よって合計は
        // 「ブランド・ホイール分析1回」の1回で固定される。
        Http::assertSentCount(1);
    }

    /**
     * --forceを指定した場合、input_hashが一致していてもAIが再度呼ばれる。
     * これが無いと#99の安定性確認(同一サイトを複数回評価)は、2回目以降が
     * 再利用結果を返すだけになり、「常に完全一致する」という見かけ上の結果に
     * なってしまい測定として成立しない(2026-07-30の指摘)。
     */
    public function test_with_force_the_api_is_called_again_even_when_input_hash_matches(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        $this->fakeOpenAiResponse();
        $websiteAnalysis = $this->makeWebsiteAnalysis();

        $this->artisan('brand-wheel:run', ['website_analysis_id' => $websiteAnalysis->id])->assertSuccessful();
        $this->artisan('brand-wheel:run', ['website_analysis_id' => $websiteAnalysis->id, '--force' => true])->assertSuccessful();
        $this->artisan('brand-wheel:run', ['website_analysis_id' => $websiteAnalysis->id, '--force' => true])->assertSuccessful();

        // 2026-08-17/2026-08-25: 上のテストと同じ理由で、改善提案AIは
        // (自社matched件数が閾値未満のため)HTTPを呼ばない。ブランド・ホイール
        // 分析自体は3回とも(1回目は通常実行、2・3回目は--forceで再実行)AIを
        // 呼ぶため、合計は3回で固定される。
        Http::assertSentCount(3);
    }

    /**
     * --outputは#99の安定性確認(3回分を別ファイルに出して差分を取る)向け。
     * BrandWheelAnalysisResultモデル自身のカラムのみから組み立てるため、
     * リードの企業名・担当者名・連絡先が構造的に含まれ得ないことを、
     * 期待されるキー集合の検証と否定的な文字列チェックの両方で確認する
     * (2026-07-30の指摘)。
     */
    public function test_output_option_writes_parsed_result_without_lead_pii(): void
    {
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);
        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $outputPath = tempnam(sys_get_temp_dir(), 'brand_wheel_test_').'.json';

        try {
            $this->artisan('brand-wheel:run', [
                'website_analysis_id' => $websiteAnalysis->id,
                '--output' => $outputPath,
            ])->assertSuccessful();

            $this->assertFileExists($outputPath);
            $contents = file_get_contents($outputPath);
            $decoded = json_decode($contents, true);

            $this->assertSame(JSON_ERROR_NONE, json_last_error());
            foreach (['brand_wheel_analysis_result_id', 'website_analysis_id', 'status', 'provider', 'model', 'prompt_version', 'usage_input_tokens', 'usage_output_tokens', 'duration_ms', 'axis_state_counts', 'core_value', 'axes'] as $key) {
                $this->assertArrayHasKey($key, $decoded);
            }

            $this->assertArrayHasKey('readable', $decoded['core_value']);
            $this->assertArrayHasKey('evidence', $decoded['core_value']);

            $firstAxis = $decoded['axes'][0];
            foreach (['axis_key', 'state', 'claimed_sub_element_count', 'discarded_count', 'matched_sub_elements', 'discarded_sub_elements'] as $key) {
                $this->assertArrayHasKey($key, $firstAxis);
            }

            $this->assertStringNotContainsString('company_name', $contents);
            $this->assertStringNotContainsString('contact_name', $contents);
            $this->assertStringNotContainsString('テスト株式会社', $contents);
            $this->assertStringNotContainsString('@example.com', $contents);
        } finally {
            @unlink($outputPath);
        }
    }

    public function test_force_is_ignored_in_production_so_the_reuse_still_applies(): void
    {
        config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);
        $this->fakeOpenAiResponse();
        $websiteAnalysis = $this->makeWebsiteAnalysis();

        // 1回目はtesting環境で正常に成功させる(コマンド自体がproductionでは
        // 起動できないため、Job単体のforceRefreshガードをここで直接検証する)。
        $this->artisan('brand-wheel:run', ['website_analysis_id' => $websiteAnalysis->id])->assertSuccessful();

        $record = BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->first();
        $second = BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        // 1回目(コマンド経由)が既にこのWebsiteAnalysisのAnalysisJob行を
        // 終端まで進めているため、直接handle()を呼ぶ前に同じ理由で明示的に
        // リセットする(RunBrandWheelAnalysisCommand本体が--force実行のたびに
        // 行うのと同じ操作)。
        \App\Models\AnalysisJob::query()
            ->where('analysis_id', $websiteAnalysis->analysis_id)
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('job_type', \App\Enums\JobType::GenerateBrandWheelAnalysis)
            ->delete();

        app()->detectEnvironment(fn () => 'production');
        try {
            (new \App\Jobs\GenerateBrandWheelAnalysisJob($second->id, forceRefresh: true))
                ->handle(app(\App\Services\BrandWheel\BrandWheelAnalysisInputFactory::class), app(\App\Services\Analysis\AnalysisPipeline::class));
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }

        // 2026-08-17/2026-08-25: 上のテストと同じ理由で、改善提案AIは
        // (自社matched件数が閾値未満のため)HTTPを呼ばない。production環境では
        // forceが無視され2回目のhandle()呼び出しは既存成功結果を再利用する
        // ため、ブランド・ホイール分析自体も1回目のコマンド実行分のみ。
        // よって合計は1回で固定される。
        Http::assertSentCount(1);
        $second->refresh();
        $this->assertSame('success', $second->status);
    }
}
