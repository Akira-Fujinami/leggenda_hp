<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\AnalysisStatus;
use App\Enums\JobType;
use App\Enums\WebsiteAnalysisStatus;
use App\Jobs\Analysis\CaptureScreenshotJob;
use App\Jobs\Analysis\DetectTechnologyJob;
use App\Jobs\Analysis\FetchRobotsJob;
use App\Jobs\Analysis\FetchSitemapJob;
use App\Jobs\Analysis\FetchStaticPageJob;
use App\Jobs\Analysis\FinalizeAnalysisJob;
use App\Jobs\Analysis\FinalizeWebsiteAnalysisJob;
use App\Jobs\Analysis\RenderPageJob;
use App\Jobs\Analysis\RunLighthouseJob;
use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\Analysis;
use App\Models\AnalysisJob;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FinalizeJobsTest extends TestCase
{
    use RefreshDatabase;

    private function completeAllWebsiteLevelJobs(WebsiteAnalysis $websiteAnalysis, bool $withOneFailure = false): void
    {
        foreach (JobType::websiteFanOutTypes() as $index => $jobType) {
            AnalysisJob::factory()->create([
                'analysis_id' => $websiteAnalysis->analysis_id,
                'website_analysis_id' => $websiteAnalysis->id,
                'job_type' => $jobType,
                'status' => ($withOneFailure && $index === 0) ? AnalysisJobStatus::Failed : AnalysisJobStatus::Completed,
            ]);
        }
    }

    public function test_finalize_website_analysis_marks_completed_when_all_jobs_succeeded(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['status' => WebsiteAnalysisStatus::Running]);
        $this->completeAllWebsiteLevelJobs($websiteAnalysis);

        (new FinalizeWebsiteAnalysisJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $websiteAnalysis->refresh();
        $this->assertSame(WebsiteAnalysisStatus::Completed, $websiteAnalysis->status);
        $this->assertSame(100, $websiteAnalysis->progress);
    }

    /**
     * FinalizeWebsiteAnalysisJobが、自身の完了後にAnalysis全体の集計
     * (FinalizeAnalysisJob)を正しくトリガーすることを確認する。
     * この配線が抜けていたことで、実際にE2Eテストで
     * 「WebsiteAnalysisはpartialに確定するのに、Analysis自身はrunningのまま
     * 進捗が進まない」というデッドロックが発生していた。
     */
    public function test_finalize_website_analysis_triggers_analysis_finalization_when_it_is_the_last_site(): void
    {
        Queue::fake([FinalizeAnalysisJob::class]);

        $analysis = Analysis::factory()->create(['status' => AnalysisStatus::Running]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create([
            'analysis_id' => $analysis->id,
            'status' => WebsiteAnalysisStatus::Running,
        ]);
        $this->completeAllWebsiteLevelJobs($websiteAnalysis);

        (new FinalizeWebsiteAnalysisJob($analysis->id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        Queue::assertPushed(FinalizeAnalysisJob::class, fn ($job) => $job->analysisId === $analysis->id);
    }

    public function test_finalize_website_analysis_marks_partial_when_a_job_failed_but_fetch_succeeded(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['status' => WebsiteAnalysisStatus::Running]);
        // FetchStaticPage以外を失敗させる -> hasUsableResult=trueのままpartialになる
        foreach (JobType::websiteFanOutTypes() as $jobType) {
            AnalysisJob::factory()->create([
                'analysis_id' => $websiteAnalysis->analysis_id,
                'website_analysis_id' => $websiteAnalysis->id,
                'job_type' => $jobType,
                'status' => $jobType === JobType::RunLighthouse ? AnalysisJobStatus::Failed : AnalysisJobStatus::Completed,
            ]);
        }

        (new FinalizeWebsiteAnalysisJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $this->assertSame(WebsiteAnalysisStatus::Partial, $websiteAnalysis->refresh()->status);
    }

    public function test_finalize_website_analysis_marks_failed_when_fetch_static_page_failed(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['status' => WebsiteAnalysisStatus::Running]);
        $this->completeAllWebsiteLevelJobs($websiteAnalysis, withOneFailure: true);

        (new FinalizeWebsiteAnalysisJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $this->assertSame(WebsiteAnalysisStatus::Failed, $websiteAnalysis->refresh()->status);
    }

    public function test_pipeline_triggers_finalize_only_after_all_fan_out_jobs_are_terminal(): void
    {
        Queue::fake([FinalizeWebsiteAnalysisJob::class]);

        $websiteAnalysis = WebsiteAnalysis::factory()->create();
        $pipeline = app(AnalysisPipeline::class);

        // FinalizeWebsiteAnalysis自身は「他の9件が終端になった結果」として
        // 起動されるジョブなので、待機対象(websiteFanOutTypes)には含まれない
        // ―― これを含めてしまうと、自分自身が終端になるまで自分を起動しない
        // という循環待ちで永久に進捗が100%手前(95%)で止まってしまう
        // (実際にE2Eテストで発生し発覚したデッドロック)。
        $types = JobType::websiteFanOutTypes();
        foreach ($types as $index => $jobType) {
            AnalysisJob::factory()->create([
                'analysis_id' => $websiteAnalysis->analysis_id,
                'website_analysis_id' => $websiteAnalysis->id,
                'job_type' => $jobType,
                'status' => AnalysisJobStatus::Completed,
            ]);

            $pipeline->maybeFinalizeWebsiteAnalysis($websiteAnalysis->id);

            if ($index < count($types) - 1) {
                Queue::assertNotPushed(FinalizeWebsiteAnalysisJob::class);
            }
        }

        Queue::assertPushed(FinalizeWebsiteAnalysisJob::class, 1);
    }

    public function test_finalize_analysis_marks_completed_when_all_websites_completed(): void
    {
        $analysis = Analysis::factory()->create(['status' => AnalysisStatus::Running]);
        WebsiteAnalysis::factory()->count(2)->create(['analysis_id' => $analysis->id, 'status' => WebsiteAnalysisStatus::Completed]);

        (new FinalizeAnalysisJob($analysis->id))->handle(app(AnalysisPipeline::class));

        $this->assertSame(AnalysisStatus::Completed, $analysis->refresh()->status);
    }

    public function test_finalize_analysis_marks_partial_when_websites_have_mixed_status(): void
    {
        $analysis = Analysis::factory()->create(['status' => AnalysisStatus::Running]);
        WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'status' => WebsiteAnalysisStatus::Completed]);
        WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'status' => WebsiteAnalysisStatus::Failed]);

        (new FinalizeAnalysisJob($analysis->id))->handle(app(AnalysisPipeline::class));

        $this->assertSame(AnalysisStatus::Partial, $analysis->refresh()->status);
    }

    public function test_start_analysis_job_registers_placeholders_and_dispatches_fan_out(): void
    {
        Queue::fake([
            FetchStaticPageJob::class, FetchRobotsJob::class, FetchSitemapJob::class, RenderPageJob::class,
            CaptureScreenshotJob::class, RunLighthouseJob::class, DetectTechnologyJob::class,
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $website = Website::factory()->for($project)->create();
        $analysis = Analysis::factory()->for($project)->create(['created_by' => $user->id]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);

        (new StartAnalysisJob($analysis->id))->handle(app(AnalysisPipeline::class));

        $this->assertSame(AnalysisStatus::Running, $analysis->refresh()->status);

        foreach (JobType::websiteLevelTypes() as $jobType) {
            $this->assertDatabaseHas('analysis_jobs', [
                'analysis_id' => $analysis->id,
                'website_analysis_id' => $websiteAnalysis->id,
                'job_type' => $jobType->value,
            ]);
        }

        Queue::assertPushed(FetchStaticPageJob::class);
        Queue::assertPushed(RunLighthouseJob::class);
        Queue::assertPushed(DetectTechnologyJob::class);
    }
}
