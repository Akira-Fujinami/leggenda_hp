<?php

namespace Tests\Unit\Services\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Jobs\GenerateBrandWheelAnalysisJob;
use App\Models\Analysis;
use App\Models\AnalysisJob;
use App\Models\BrandWheelAnalysisResult;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 依頼D-2: dispatchBrandWheelAnalysisAfterCrawl()は、CrawlWebsiteJob/
 * CrawlWebsitePageJob/RenderCrawledPageJobのいずれの終端(正常終了・上限
 * 打ち切り・failed())からも呼ばれうるため、複数回呼ばれても
 * BrandWheelAnalysisResultが1行しかできないこと(冪等性)を検証する。
 *
 * 採用した方式: website_analyses.brand_wheel_dispatched_atへの「nullの行
 * だけを対象にした条件付きUPDATE」で一度だけ勝者を決める
 * (DBの一意制約は意図的に使わない ―― RunBrandWheelAnalysisCommand --force
 * が同一website_analysis_idに複数行を作る機能と衝突するため)。
 */
class AnalysisPipelineBrandWheelDispatchIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsiteAnalysis(): WebsiteAnalysis
    {
        $project = Project::factory()->create();
        $analysis = Analysis::factory()->for($project)->create();
        $website = Website::factory()->for($project)->create(['is_primary' => true]);

        return WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
    }

    public function test_calling_it_twice_creates_only_one_brand_wheel_analysis_result(): void
    {
        Queue::fake();
        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $pipeline = app(AnalysisPipeline::class);

        $pipeline->dispatchBrandWheelAnalysisAfterCrawl($websiteAnalysis->analysis_id, $websiteAnalysis->id);
        $pipeline->dispatchBrandWheelAnalysisAfterCrawl($websiteAnalysis->analysis_id, $websiteAnalysis->id);

        $this->assertSame(1, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
        Queue::assertPushed(GenerateBrandWheelAnalysisJob::class, 1);
        $this->assertNotNull($websiteAnalysis->fresh()->brand_wheel_dispatched_at);
    }

    public function test_calling_it_three_times_simulating_multiple_termination_paths_still_creates_only_one_result(): void
    {
        Queue::fake();
        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $pipeline = app(AnalysisPipeline::class);

        // CrawlWebsiteJob(seed 0件)・CrawlWebsitePageJob(上限打ち切り)・
        // RenderCrawledPageJob(候補0件)のいずれからも同じ経路が呼ばれうる
        // ことを模擬する。
        $pipeline->dispatchBrandWheelAnalysisAfterCrawl($websiteAnalysis->analysis_id, $websiteAnalysis->id);
        $pipeline->dispatchBrandWheelAnalysisAfterCrawl($websiteAnalysis->analysis_id, $websiteAnalysis->id);
        $pipeline->dispatchBrandWheelAnalysisAfterCrawl($websiteAnalysis->analysis_id, $websiteAnalysis->id);

        $this->assertSame(1, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
        Queue::assertPushed(GenerateBrandWheelAnalysisJob::class, 1);
    }

    /**
     * 依頼M-1: crawl_site=trueのとき、CrawlWebsiteJobが0件seedで即座に
     * dispatchBrandWheelAnalysisAfterCrawl()を呼ぶ経路(RenderCrawledPagesは
     * 一度もRunningにならない、Pendingのまま)でも、両方のAnalysisJob行が
     * 終端(Completed)になり、websiteFanOutTypes()を使う完了判定
     * (maybeFinalizeWebsiteAnalysis())が永久に足止めされないこと。
     */
    public function test_crawl_site_enabled_completes_both_crawl_job_rows_even_when_render_never_started(): void
    {
        Queue::fake();
        $project = Project::factory()->create();
        $analysis = Analysis::factory()->for($project)->create(['crawl_site' => true, 'skip_brand_wheel' => false]);
        $website = Website::factory()->for($project)->create(['is_primary' => true]);
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
        $pipeline = app(AnalysisPipeline::class);

        $pipeline->registerWebsiteJobPlaceholders($websiteAnalysis);
        // CrawlWebsiteJobがseedを試みてRunningになった後、0件seedで直接
        // dispatchBrandWheelAnalysisAfterCrawl()を呼ぶ経路を模擬する
        // (RenderCrawledPagesはPendingのまま一度もRunningにならない)。
        $pipeline->markRunning($analysis->id, $websiteAnalysis->id, JobType::CrawlWebsite);

        $this->assertSame(
            AnalysisJobStatus::Pending,
            AnalysisJob::query()->where('website_analysis_id', $websiteAnalysis->id)->where('job_type', JobType::RenderCrawledPages)->first()->status,
        );

        $pipeline->dispatchBrandWheelAnalysisAfterCrawl($analysis->id, $websiteAnalysis->id);

        foreach ([JobType::CrawlWebsite, JobType::RenderCrawledPages] as $jobType) {
            $job = AnalysisJob::query()->where('website_analysis_id', $websiteAnalysis->id)->where('job_type', $jobType)->first();
            $this->assertSame(AnalysisJobStatus::Completed, $job->status, "{$jobType->value}がCompletedになっていない");
        }
    }

    /**
     * 依頼M-1: crawl_site=falseのときはCrawlWebsite/RenderCrawledPagesの
     * AnalysisJob行がそもそも存在しない(registerWebsiteJobPlaceholders()で
     * 除外される)。dispatchBrandWheelAnalysisAfterCrawl()を呼んでも新規に
     * 作成されないこと(進捗の分母を変えないための最重要条件)。
     */
    public function test_crawl_site_disabled_never_creates_crawl_job_rows(): void
    {
        Queue::fake();
        $websiteAnalysis = $this->makeWebsiteAnalysis(); // crawl_site既定false
        $pipeline = app(AnalysisPipeline::class);
        $pipeline->registerWebsiteJobPlaceholders($websiteAnalysis);

        $pipeline->dispatchBrandWheelAnalysisAfterCrawl($websiteAnalysis->analysis_id, $websiteAnalysis->id);

        foreach ([JobType::CrawlWebsite, JobType::RenderCrawledPages] as $jobType) {
            $this->assertDatabaseMissing('analysis_jobs', [
                'website_analysis_id' => $websiteAnalysis->id,
                'job_type' => $jobType->value,
            ]);
        }
    }
}
