<?php

namespace Tests\Unit\Enums;

use App\Enums\JobType;
use Tests\TestCase;

/**
 * JobType::weight()はサイト単位の進捗計算(ProgressCalculator)の基礎。
 *
 * 依頼N(2026-08-25): ProgressCalculator::forWebsiteAnalysis()を「行が
 * 存在するジョブ種別の重みの合計」で正規化する方式に変更したため、
 * websiteLevelTypes()全体の合計が100であることはもはや進捗計算の
 * 正しさに必須ではなくなった(正規化により、どの部分集合が欠けても
 * 100%まで到達する)。CrawlWebsite/RenderCrawledPagesは依頼M-1で追加した
 * 独立の重み(合計が100を超えてよい、依頼N指定)であるため、この2種を
 * 除いた「基本16種」の合計が100であることだけを保証する。
 */
class JobTypeWeightTest extends TestCase
{
    public function test_base_job_type_weights_excluding_crawl_sum_to_100(): void
    {
        $baseTypes = array_values(array_filter(
            JobType::websiteLevelTypes(),
            fn (JobType $type) => ! in_array($type, [JobType::CrawlWebsite, JobType::RenderCrawledPages], true),
        ));

        $sum = array_sum(array_map(fn (JobType $type) => $type->weight(), $baseTypes));

        $this->assertSame(100, $sum);
    }

    public function test_crawl_website_and_render_crawled_pages_weights_are_independent_of_the_100_budget(): void
    {
        // CrawlWebsite/RenderCrawledPagesは依頼M-1で追加した独立の重みであり、
        // 基本16種の合計100とは別枠(ProgressCalculatorが行の存在するジョブ種別
        // の重みの合計で正規化するため、絶対値ではなく比だけが意味を持つ)。
        $this->assertSame(12, JobType::CrawlWebsite->weight());
        $this->assertSame(8, JobType::RenderCrawledPages->weight());
    }

    public function test_analysis_level_orchestration_jobs_carry_no_weight(): void
    {
        $this->assertSame(0, JobType::StartAnalysis->weight());
        $this->assertSame(0, JobType::FinalizeAnalysis->weight());
    }

    public function test_website_fan_out_types_excludes_only_finalize_website_analysis(): void
    {
        $fanOut = JobType::websiteFanOutTypes();
        $levelTypes = JobType::websiteLevelTypes();

        $this->assertCount(count($levelTypes) - 1, $fanOut);
        $this->assertNotContains(JobType::FinalizeWebsiteAnalysis, $fanOut);
    }
}
