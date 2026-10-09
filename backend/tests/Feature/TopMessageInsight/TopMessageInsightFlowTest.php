<?php

namespace Tests\Feature\TopMessageInsight;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Jobs\GenerateBrandWheelAnalysisJob;
use App\Jobs\GenerateTopMessageInsightJob;
use App\Models\Analysis;
use App\Models\AnalysisJob;
use App\Models\BrandWheelAnalysisResult;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\BrandWheel\BrandWheelAnalysisInputFactory;
use App\Services\TopMessageInsight\TopMessageInsightService;
use App\Services\TopMessageInsight\TopMessageInsightStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;

/**
 * 依頼CQ-3「いつ実行し、どこに置くか」: 比較の流れの中で、ブランド・ホイールの判定が終わったあとに
 * 会社ごとに1回。失敗しても比較全体は失敗させない。
 */
class TopMessageInsightFlowTest extends TestCase
{
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
        config(['services.brand_wheel_ai.provider' => 'mock', 'analysis.allow_mock_providers' => true, 'top_message_insight.enabled' => true]);
    }

    private function brandWheelRecord(\App\Models\WebsiteAnalysis $wa): BrandWheelAnalysisResult
    {
        return BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $wa->analysis_id,
            'website_analysis_id' => $wa->id,
            'status' => 'pending',
            'is_mock' => false,
        ]);
    }

    private function runBrandWheel(BrandWheelAnalysisResult $record): void
    {
        (new GenerateBrandWheelAnalysisJob($record->id))->handle(app(BrandWheelAnalysisInputFactory::class), app(AnalysisPipeline::class));
    }

    public function test_the_job_is_dispatched_once_per_company_after_brand_wheel_finishes_in_a_comparison(): void
    {
        Queue::fake();
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedStandardCompany($wa);

        $this->runBrandWheel($this->brandWheelRecord($wa));

        Queue::assertPushed(GenerateTopMessageInsightJob::class, 1);
        Queue::assertPushedOn('ai', GenerateTopMessageInsightJob::class, fn (GenerateTopMessageInsightJob $job) => $job->websiteAnalysisId === $wa->id);
    }

    public function test_no_job_is_dispatched_for_a_non_comparison_analysis(): void
    {
        Queue::fake();
        $project = Project::factory()->create();
        $website = Website::factory()->for($project)->create(['is_primary' => true]);
        $analysis = Analysis::factory()->for($project)->create();
        $wa = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
        $this->seedStandardCompany($wa);

        $this->runBrandWheel($this->brandWheelRecord($wa));

        Queue::assertNotPushed(GenerateTopMessageInsightJob::class);
    }

    public function test_no_job_is_dispatched_when_the_result_already_exists(): void
    {
        Queue::fake();
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedStandardCompany($wa);
        app(TopMessageInsightStore::class)->write($wa->analysis_id, $wa->id, ['status' => 'not_created', 'reason' => 'no_message_page']);

        $this->runBrandWheel($this->brandWheelRecord($wa));

        Queue::assertNotPushed(GenerateTopMessageInsightJob::class);
    }

    public function test_the_whole_flow_leaves_a_result_file_next_to_the_other_analysis_files(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedStandardCompany($wa);
        $fake = $this->fakeTopMessageProvider($this->validAiOutput());

        $this->runBrandWheel($this->brandWheelRecord($wa));

        $this->assertSame(1, $fake->calls);
        Storage::disk('analysis')->assertExists("analyses/{$wa->analysis_id}/websites/{$wa->id}/top_message_insight.json");
        $this->assertSame('created', app(TopMessageInsightStore::class)->read($wa->analysis_id, $wa->id)['status']);

        // 同じ会社のブランド・ホイールをもう一度走らせても(再実行)、AIは呼び直さない。
        AnalysisJob::query()->where('website_analysis_id', $wa->id)->delete();
        $this->runBrandWheel($this->brandWheelRecord($wa));
        $this->assertSame(1, $fake->calls);
    }

    public function test_when_the_insight_job_throws_the_comparison_still_completes_and_only_the_page_is_missing(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedStandardCompany($wa);
        app()->instance(TopMessageInsightService::class, new class extends TopMessageInsightService
        {
            public function __construct() {}

            public function generate(WebsiteAnalysis $websiteAnalysis, bool $force = false): array
            {
                throw new \RuntimeException('想定外の例外');
            }
        });

        $record = $this->brandWheelRecord($wa);
        $this->runBrandWheel($record);

        $record->refresh();
        $this->assertContains($record->status, ['success', 'insufficient_input'], 'ブランド・ホイールの判定は影響を受けない');
        $this->assertSame(
            AnalysisJobStatus::Completed,
            AnalysisJob::query()->where('website_analysis_id', $wa->id)->where('job_type', JobType::GenerateBrandWheelAnalysis)->firstOrFail()->status,
        );
        $stored = app(TopMessageInsightStore::class)->read($wa->analysis_id, $wa->id);
        $this->assertTrue($stored === null || $stored['status'] !== 'created', 'そのページだけが無い');
    }

    public function test_the_insight_job_itself_swallows_exceptions(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        app()->instance(TopMessageInsightService::class, new class extends TopMessageInsightService
        {
            public function __construct() {}

            public function generate(WebsiteAnalysis $websiteAnalysis, bool $force = false): array
            {
                throw new \RuntimeException('想定外の例外');
            }
        });

        (new GenerateTopMessageInsightJob($wa->id))->handle(app(TopMessageInsightService::class), app(\App\Services\TopMessageInsight\TopMessageInsightDispatcher::class));

        $this->assertTrue(true, '例外がジョブの外へ出ない');
    }

    public function test_the_insight_job_has_a_single_attempt_and_a_timeout_longer_than_the_ai_call(): void
    {
        $job = new GenerateTopMessageInsightJob(1);

        $this->assertSame(1, $job->tries);
        $this->assertSame(((int) config('top_message_insight.timeout')) + 30, $job->timeout);
    }

    public function test_disabling_the_feature_stops_dispatch(): void
    {
        Queue::fake();
        config(['top_message_insight.enabled' => false]);
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedStandardCompany($wa);

        $this->runBrandWheel($this->brandWheelRecord($wa));

        Queue::assertNotPushed(GenerateTopMessageInsightJob::class);
    }

    public function test_a_failure_is_recorded_as_a_retryable_not_created_result_and_the_next_run_tries_again(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedStandardCompany($wa);
        $this->fakeTopMessageProvider(fn () => throw new \App\Services\TopMessageInsight\TopMessageInsightException('AI_TIMEOUT', 'timeout'));

        (new GenerateTopMessageInsightJob($wa->id))->handle(app(TopMessageInsightService::class), app(\App\Services\TopMessageInsight\TopMessageInsightDispatcher::class));

        $stored = app(TopMessageInsightStore::class)->read($wa->analysis_id, $wa->id);
        $this->assertSame('not_created', $stored['status']);
        $this->assertSame('ai_error', $stored['reason']);

        // 次の実行ではやり直す(失敗の記録は確定した結果ではない)。
        $fake = $this->fakeTopMessageProvider($this->validAiOutput());
        (new GenerateTopMessageInsightJob($wa->id))->handle(app(TopMessageInsightService::class), app(\App\Services\TopMessageInsight\TopMessageInsightDispatcher::class));
        $this->assertSame(1, $fake->calls);
        $this->assertSame('created', app(TopMessageInsightStore::class)->read($wa->analysis_id, $wa->id)['status']);
    }

    public function test_when_enabled_the_website_analysis_is_not_finalized_until_the_insight_job_has_finished(): void
    {
        Queue::fake();
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedStandardCompany($wa);
        $this->terminateAllJobs($wa);
        $pipeline = app(AnalysisPipeline::class);

        $pipeline->maybeFinalizeWebsiteAnalysis($wa->id);
        Queue::assertNotPushed(\App\Jobs\Analysis\FinalizeWebsiteAnalysisJob::class);

        // ジョブが終わった(成功)ので、比較の完了が進む。
        app(TopMessageInsightStore::class)->write($wa->analysis_id, $wa->id, ['status' => 'not_created', 'reason' => 'no_message_page']);
        $pipeline->maybeFinalizeWebsiteAnalysis($wa->id);
        Queue::assertPushed(\App\Jobs\Analysis\FinalizeWebsiteAnalysisJob::class, 1);
    }

    public function test_a_failing_insight_job_does_not_keep_the_comparison_from_finishing(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedStandardCompany($wa);
        $this->fakeTopMessageProvider(fn () => throw new \RuntimeException('想定外'));
        $this->terminateAllJobs($wa);
        Queue::fake();

        (new GenerateTopMessageInsightJob($wa->id))->handle(app(TopMessageInsightService::class), app(\App\Services\TopMessageInsight\TopMessageInsightDispatcher::class));

        Queue::assertPushed(\App\Jobs\Analysis\FinalizeWebsiteAnalysisJob::class);
    }

    public function test_the_wait_ends_after_the_configured_minutes_even_if_the_job_never_comes(): void
    {
        Queue::fake();
        config(['top_message_insight.wait_max_minutes' => 10]);
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->terminateAllJobs($wa);
        AnalysisJob::query()->where('website_analysis_id', $wa->id)->where('job_type', JobType::GenerateBrandWheelAnalysis)->update(['completed_at' => now()->subMinutes(11)]);

        app(AnalysisPipeline::class)->maybeFinalizeWebsiteAnalysis($wa->id);

        Queue::assertPushed(\App\Jobs\Analysis\FinalizeWebsiteAnalysisJob::class);
    }

    public function test_when_disabled_nothing_is_dispatched_nothing_is_waited_for_and_no_ai_is_called(): void
    {
        Queue::fake();
        config(['top_message_insight.enabled' => false]);
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedStandardCompany($wa);
        $fake = $this->fakeTopMessageProvider($this->validAiOutput());

        $this->runBrandWheel($this->brandWheelRecord($wa));
        Queue::assertNotPushed(GenerateTopMessageInsightJob::class);

        $this->terminateAllJobs($wa);
        app(AnalysisPipeline::class)->maybeFinalizeWebsiteAnalysis($wa->id);
        Queue::assertPushed(\App\Jobs\Analysis\FinalizeWebsiteAnalysisJob::class);

        $this->assertSame(0, $fake->calls);
        $this->assertFalse(app(TopMessageInsightStore::class)->exists($wa->analysis_id, $wa->id));
    }

    public function test_the_default_of_enabled_is_false(): void
    {
        $config = require base_path('config/top_message_insight.php');
        $this->assertFalse($config['enabled']);
    }

    private function terminateAllJobs(WebsiteAnalysis $wa): void
    {
        app(AnalysisPipeline::class)->registerWebsiteJobPlaceholders($wa->fresh());
        AnalysisJob::query()->where('website_analysis_id', $wa->id)->update(['status' => AnalysisJobStatus::Completed->value, 'completed_at' => now()]);
    }
}
