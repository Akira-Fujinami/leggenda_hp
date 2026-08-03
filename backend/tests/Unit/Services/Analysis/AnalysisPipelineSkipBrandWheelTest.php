<?php

namespace Tests\Unit\Services\Analysis;

use App\Jobs\Analysis\FetchExternalSeoDataJob;
use App\Jobs\Analysis\FetchRobotsJob;
use App\Jobs\Analysis\FetchSitemapJob;
use App\Jobs\Analysis\FetchStaticPageJob;
use App\Jobs\Analysis\RenderPageJob;
use App\Jobs\GenerateBrandWheelAnalysisJob;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ブランド・ホイール(6軸)分析はOpenAIへの課金呼び出し・サイト本文の外部
 * 送信を伴うため、Analysis.skip_brand_wheelの既定はtrue(実行しない)。
 * skip_lighthouse/skip_screenshots(既定false=実行する)とは向きが逆 ――
 * 将来Analysisを作る経路が増えたとき、指定を忘れても黙ってOpenAIを
 * 呼び始めない側を既定にする(2026-08-03のユーザー指摘)。
 */
class AnalysisPipelineSkipBrandWheelTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsiteAnalysis(bool $skipBrandWheel): WebsiteAnalysis
    {
        $website = Website::factory()->create(['url' => 'https://example.com', 'normalized_url' => 'https://example.com']);
        $analysis = Analysis::factory()->create(['skip_brand_wheel' => $skipBrandWheel]);

        return WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id, 'website_id' => $website->id]);
    }

    public function test_skip_brand_wheel_true_does_not_register_a_placeholder(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis(skipBrandWheel: true);

        app(AnalysisPipeline::class)->registerWebsiteJobPlaceholders($websiteAnalysis);

        $this->assertDatabaseMissing('analysis_jobs', [
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => 'generate_brand_wheel_analysis',
        ]);
    }

    public function test_skip_brand_wheel_false_registers_a_placeholder(): void
    {
        $websiteAnalysis = $this->makeWebsiteAnalysis(skipBrandWheel: false);

        app(AnalysisPipeline::class)->registerWebsiteJobPlaceholders($websiteAnalysis);

        $this->assertDatabaseHas('analysis_jobs', [
            'website_analysis_id' => $websiteAnalysis->id,
            'job_type' => 'generate_brand_wheel_analysis',
        ]);
    }

    public function test_new_analysis_defaults_to_skip_brand_wheel_true_when_not_explicitly_set(): void
    {
        // AnalysisFactoryが独自にskip_brand_wheelを上書きしていない限り、
        // モデルの実カラム既定(migration側でdefault(true))が反映される。
        $analysis = Analysis::factory()->create();

        $this->assertTrue($analysis->fresh()->skip_brand_wheel);
    }

    /**
     * @var list<class-string>
     */
    private const OTHER_FAN_OUT_JOBS = [
        FetchStaticPageJob::class, FetchRobotsJob::class, FetchSitemapJob::class,
        FetchExternalSeoDataJob::class, RenderPageJob::class,
    ];

    public function test_dispatch_website_fan_out_skips_brand_wheel_when_flag_is_true(): void
    {
        Queue::fake([GenerateBrandWheelAnalysisJob::class, ...self::OTHER_FAN_OUT_JOBS]);

        $websiteAnalysis = $this->makeWebsiteAnalysis(skipBrandWheel: true);

        app(AnalysisPipeline::class)->dispatchWebsiteFanOut($websiteAnalysis);

        Queue::assertNotPushed(GenerateBrandWheelAnalysisJob::class);
        $this->assertSame(0, BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->count());
    }

    public function test_dispatch_website_fan_out_dispatches_brand_wheel_when_flag_is_false(): void
    {
        Queue::fake([GenerateBrandWheelAnalysisJob::class, ...self::OTHER_FAN_OUT_JOBS]);

        $websiteAnalysis = $this->makeWebsiteAnalysis(skipBrandWheel: false);

        app(AnalysisPipeline::class)->dispatchWebsiteFanOut($websiteAnalysis);

        $record = BrandWheelAnalysisResult::query()->where('website_analysis_id', $websiteAnalysis->id)->first();
        $this->assertNotNull($record);
        $this->assertSame('pending', $record->status);
        Queue::assertPushedOn('ai', GenerateBrandWheelAnalysisJob::class, fn ($job) => $job->brandWheelAnalysisResultId === $record->id);
    }
}
