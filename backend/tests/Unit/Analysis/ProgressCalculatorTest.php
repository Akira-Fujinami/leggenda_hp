<?php

namespace Tests\Unit\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Models\AnalysisJob;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\ProgressCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgressCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private ProgressCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new ProgressCalculator;
    }

    private function job(JobType $type, AnalysisJobStatus $status): AnalysisJob
    {
        return AnalysisJob::factory()->make(['job_type' => $type, 'status' => $status]);
    }

    public function test_it_returns_zero_when_no_jobs_are_done(): void
    {
        $jobs = collect([$this->job(JobType::FetchStaticPage, AnalysisJobStatus::Pending)]);

        $this->assertSame(0, $this->calculator->forWebsiteAnalysis($jobs));
    }

    /**
     * 依頼N: 分母($possible)はjobsコレクション内に行が存在する種別の重み
     * だけの合計になる(=正規化)。FetchStaticPage(10)+FetchRobots(4)+
     * RunLighthouse(13)の3種のみが存在する場合、possible=27、
     * earned=10+4+0(Runningでprogress未設定=0のため加点なし)=14、
     * round(100*14/27)=52。
     */
    public function test_it_sums_weights_of_completed_jobs(): void
    {
        $jobs = collect([
            $this->job(JobType::FetchStaticPage, AnalysisJobStatus::Completed),
            $this->job(JobType::FetchRobots, AnalysisJobStatus::Completed),
            $this->job(JobType::RunLighthouse, AnalysisJobStatus::Running), // progress未設定(既定0)のため加点なし
        ]);

        $this->assertSame(52, $this->calculator->forWebsiteAnalysis($jobs));
    }

    /**
     * 依頼N: Failedのジョブも「完了扱い」で満額が入ることを、他に未完了の
     * ジョブが混在する状況で確認する(単独だと正規化により自明に100%へ
     * 到達してしまい、Failedが「満額」であることの検証にならないため)。
     * possible=FetchStaticPage(10)+FetchRobots(4)=14、earned=10(Failed)、
     * round(100*10/14)=71。
     */
    public function test_failed_jobs_still_count_toward_progress(): void
    {
        $jobs = collect([
            $this->job(JobType::FetchStaticPage, AnalysisJobStatus::Failed),
            $this->job(JobType::FetchRobots, AnalysisJobStatus::Pending),
        ]);

        $this->assertSame(71, $this->calculator->forWebsiteAnalysis($jobs));
    }

    /**
     * 依頼M-1: status=Runningのジョブは、progressカラム(0-99)に応じた
     * 部分点を得る。依頼N: 正規化後は分母に他の存在する種別も含まれる
     * ため、CrawlWebsite(重み12)のみでは自明に50%へ正規化されてしまう。
     * RenderCrawledPages(重み8、Pending=未加点)を混在させ、possible=20、
     * earned=12*0.5=6、round(100*6/20)=30で「部分点が正しく分母に対する
     * 割合になる」ことを検証する。
     */
    public function test_running_job_with_progress_gets_partial_credit(): void
    {
        $jobs = collect([
            $this->job(JobType::CrawlWebsite, AnalysisJobStatus::Running)->fill(['progress' => 50]),
            $this->job(JobType::RenderCrawledPages, AnalysisJobStatus::Pending),
        ]);

        $this->assertSame(30, $this->calculator->forWebsiteAnalysis($jobs));
    }

    /**
     * 依頼M-1の最重要不変条件: 既存のJobType(CrawlWebsite/RenderCrawledPages
     * 以外)はmarkRunning()がprogressカラムに一切触れないため既定値0のまま
     * status=Runningを過ごす。部分点ロジックを追加しても、これらのJobType
     * ではRunning中は常に0点のまま(=この変更を入れる前と完全に同一の
     * 挙動)であることを明示的に確認する。
     */
    public function test_existing_job_types_contribute_nothing_while_running_regardless_of_this_change(): void
    {
        $jobs = collect([
            $this->job(JobType::FetchStaticPage, AnalysisJobStatus::Running),
            $this->job(JobType::RunLighthouse, AnalysisJobStatus::Running),
            $this->job(JobType::GenerateBrandWheelAnalysis, AnalysisJobStatus::Running),
        ]);

        $this->assertSame(0, $this->calculator->forWebsiteAnalysis($jobs));
    }

    /**
     * progress=0(実行開始直後、まだ1ページも処理していない)のRunning行は
     * 加点0 ―― 「開始した瞬間に満額」にはならないことを確認する。
     */
    public function test_running_job_at_progress_zero_contributes_nothing(): void
    {
        $jobs = collect([
            $this->job(JobType::CrawlWebsite, AnalysisJobStatus::Running)->fill(['progress' => 0]),
        ]);

        $this->assertSame(0, $this->calculator->forWebsiteAnalysis($jobs));
    }

    public function test_all_website_level_jobs_completed_reaches_100(): void
    {
        $jobs = collect(JobType::websiteLevelTypes())
            ->map(fn ($type) => $this->job($type, AnalysisJobStatus::Completed));

        $this->assertSame(100, $this->calculator->forWebsiteAnalysis($jobs));
    }

    /**
     * 依頼N最重要: excludeSkippedJobTypes()により対象外にされた種別は
     * AnalysisJob行自体が作られず($job===null)、jobsコレクションにも
     * 現れない。依頼M-1までの実装(絶対値の合計)ではこれらの重みが分母にも
     * 一切現れず、到達できる進捗の上限が100%未満に固定される不具合が
     * あった(例: リード診断はRunLighthouse/CaptureScreenshot系/
     * CrawlWebsite/RenderCrawledPagesを省略するため、旧実装では
     * 100-13-7-7-12-8=53点で頭打ちだった)。正規化後は、それらを除いた
     * 残り13種の重みだけが分母になるため、全て完了すれば100%に到達する。
     */
    public function test_jobs_excluded_from_the_collection_do_not_cap_progress_below_100(): void
    {
        $excluded = [
            JobType::RunLighthouse,
            JobType::CaptureScreenshotDesktop,
            JobType::CaptureScreenshotMobile,
            JobType::CrawlWebsite,
            JobType::RenderCrawledPages,
        ];
        $presentTypes = array_values(array_filter(
            JobType::websiteLevelTypes(),
            fn (JobType $type) => ! in_array($type, $excluded, true),
        ));

        $jobs = collect($presentTypes)->map(fn ($type) => $this->job($type, AnalysisJobStatus::Completed));

        $this->assertSame(100, $this->calculator->forWebsiteAnalysis($jobs));
    }

    public function test_analysis_progress_is_average_of_website_analyses(): void
    {
        $websiteAnalyses = collect([
            WebsiteAnalysis::factory()->make(['progress' => 100]),
            WebsiteAnalysis::factory()->make(['progress' => 50]),
        ]);

        $this->assertSame(75, $this->calculator->forAnalysis($websiteAnalyses));
    }

    public function test_analysis_progress_is_zero_when_no_website_analyses(): void
    {
        $this->assertSame(0, $this->calculator->forAnalysis(collect()));
    }
}
