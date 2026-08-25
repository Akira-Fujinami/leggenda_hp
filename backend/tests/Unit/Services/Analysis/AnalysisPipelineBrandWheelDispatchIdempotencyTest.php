<?php

namespace Tests\Unit\Services\Analysis;

use App\Jobs\GenerateBrandWheelAnalysisJob;
use App\Models\Analysis;
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
}
