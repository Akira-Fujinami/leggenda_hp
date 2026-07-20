<?php

namespace Tests\Unit\Jobs;

use App\Enums\MetricResultStatus;
use App\Jobs\GenerateAiAnalysisJob;
use App\Models\AiAnalysisResult;
use App\Models\Analysis;
use App\Models\CategoryDefinition;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenerateAiAnalysisJobTest extends TestCase
{
    use RefreshDatabase;

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

        return $websiteAnalysis;
    }

    public function test_mock_provider_produces_a_successful_structured_result(): void
    {
        config(['services.ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = AiAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
            'is_mock' => false,
        ]);

        (new GenerateAiAnalysisJob($record->id))->handle(app(\App\Services\AiAnalysis\AiAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('success', $record->status);
        $this->assertTrue($record->is_mock);
        $this->assertSame('mock', $record->provider);
        $this->assertNotEmpty($record->input_hash);
        $this->assertNotNull($record->generated_at);
    }

    public function test_openai_success_is_parsed_and_stored_with_usage(): void
    {
        config(['services.ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'summary' => 'テストサイトは概ね良好です。',
                        'strengths' => [['title' => '強み', 'description' => '説明', 'evidence_metric_keys' => ['title_present']]],
                        'weaknesses' => [],
                        'priority_actions' => [],
                        'competitor_insights' => [],
                        'cautions' => [],
                        'confidence' => 0.75,
                    ])]],
                ],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40],
            ], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = AiAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
            'is_mock' => false,
        ]);

        (new GenerateAiAnalysisJob($record->id))->handle(app(\App\Services\AiAnalysis\AiAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('success', $record->status);
        $this->assertFalse($record->is_mock);
        $this->assertSame('openai', $record->provider);
        $this->assertSame(120, $record->usage_input_tokens);
        $this->assertSame(40, $record->usage_output_tokens);
        $this->assertSame('title_present', $record->strengths[0]['evidence_metric_keys'][0]);
    }

    public function test_openai_response_referencing_an_unknown_metric_key_is_filtered_out(): void
    {
        config(['services.ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'summary' => 'summary',
                        'strengths' => [['title' => 't', 'description' => 'd', 'evidence_metric_keys' => ['nonexistent_metric_key']]],
                        'weaknesses' => [],
                        'priority_actions' => [],
                        'competitor_insights' => [],
                        'cautions' => [],
                        'confidence' => 0.5,
                    ])]],
                ],
            ], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = AiAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateAiAnalysisJob($record->id))->handle(app(\App\Services\AiAnalysis\AiAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('success', $record->status);
        $this->assertSame([], $record->strengths[0]['evidence_metric_keys']);
    }

    public function test_openai_malformed_json_marks_result_as_error_without_failing_the_job(): void
    {
        config(['services.ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'not valid json']]],
            ], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = AiAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateAiAnalysisJob($record->id))->handle(app(\App\Services\AiAnalysis\AiAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('error', $record->status);
        $this->assertSame('AI_INVALID_JSON', $record->error_code);
    }

    public function test_openai_auth_failure_marks_result_as_error_without_failing_the_job(): void
    {
        config(['services.ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);

        Http::fake(['api.openai.com/*' => Http::response(['error' => 'unauthorized'], 401)]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = AiAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateAiAnalysisJob($record->id))->handle(app(\App\Services\AiAnalysis\AiAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('error', $record->status);
        $this->assertSame('AI_AUTH_FAILED', $record->error_code);
    }

    public function test_provider_unset_marks_result_as_error_without_hanging(): void
    {
        config(['services.ai.provider' => 'totally-unknown']);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = AiAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id,
            'website_analysis_id' => $websiteAnalysis->id,
            'status' => 'pending',
        ]);

        (new GenerateAiAnalysisJob($record->id))->handle(app(\App\Services\AiAnalysis\AiAnalysisInputFactory::class));

        $record->refresh();
        $this->assertSame('error', $record->status);
        $this->assertSame('AI_PROVIDER_INVALID', $record->error_code);
    }

    public function test_repeat_call_with_identical_input_reuses_prior_success_without_calling_the_api_again(): void
    {
        config(['services.ai.provider' => 'openai', 'services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'summary' => 'summary', 'strengths' => [], 'weaknesses' => [], 'priority_actions' => [],
                    'competitor_insights' => [], 'cautions' => [], 'confidence' => 0.6,
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();

        $first = AiAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);
        (new GenerateAiAnalysisJob($first->id))->handle(app(\App\Services\AiAnalysis\AiAnalysisInputFactory::class));

        $second = AiAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);
        (new GenerateAiAnalysisJob($second->id))->handle(app(\App\Services\AiAnalysis\AiAnalysisInputFactory::class));

        Http::assertSentCount(1);
        $second->refresh();
        $this->assertSame('success', $second->status);
    }

    public function test_result_is_scoped_by_website_analysis_and_never_used_for_scoring(): void
    {
        config(['services.ai.provider' => 'mock', 'analysis.allow_mock_providers' => true]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $record = AiAnalysisResult::factory()->create([
            'analysis_id' => $websiteAnalysis->analysis_id, 'website_analysis_id' => $websiteAnalysis->id, 'status' => 'pending',
        ]);

        (new GenerateAiAnalysisJob($record->id))->handle(app(\App\Services\AiAnalysis\AiAnalysisInputFactory::class));

        // AI結果はmetric_resultsに一切書き込まれない(スコアへ影響しない)。
        $this->assertSame(1, MetricResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }
}
