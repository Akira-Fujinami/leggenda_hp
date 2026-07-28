<?php

namespace Tests\Unit\Enums;

use App\Enums\JobType;
use Tests\TestCase;

/**
 * JobType::weight()はサイト単位の進捗計算(ProgressCalculator)の基礎であり、
 * websiteLevelTypes()の合計が100からズレると進捗が100%に到達しない、
 * または100%を超えるといった不具合につながる。新しいJobTypeを追加する
 * たびに、既存の重みを再配分して合計を100に保つ必要があることを
 * このテストで機械的に保証する。
 */
class JobTypeWeightTest extends TestCase
{
    public function test_website_level_job_type_weights_sum_to_100(): void
    {
        $sum = array_sum(array_map(fn (JobType $type) => $type->weight(), JobType::websiteLevelTypes()));

        $this->assertSame(100, $sum);
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
