<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Enums\PageType;
use App\Jobs\Analysis\AnalyzeRecruitPageJob;
use App\Jobs\Analysis\FetchRecruitPageJob;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * $recruitUrlは呼び出し元(AnalysisPipeline::resolveRecruitUrl())が既に
 * 絶対URLへ解決済みのものを渡す前提のため、このJob自体は相対URL解決を
 * 行わない(RelativeUrlResolverTest/AnalysisPipelineRecruitUrlResolutionTest
 * 側でその解決ロジック自体を検証する)。
 */
class FetchRecruitPageJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
    }

    private function makeWebsiteAnalysis(): WebsiteAnalysis
    {
        return WebsiteAnalysis::factory()->create();
    }

    public function test_it_does_nothing_and_completes_when_no_recruit_url_was_detected(): void
    {
        Queue::fake([AnalyzeRecruitPageJob::class]);
        Http::fake();

        $websiteAnalysis = $this->makeWebsiteAnalysis();

        (new FetchRecruitPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id, null))->handle(app(AnalysisPipeline::class));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('analysis_pages', ['website_analysis_id' => $websiteAnalysis->id, 'page_type' => PageType::Recruit->value]);

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::FetchRecruitPage)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        // 「見つからない」も終端状態として扱い、必ず次のJob(no-opになる)を起動する。
        Queue::assertPushed(AnalyzeRecruitPageJob::class);
    }

    public function test_it_fetches_the_given_absolute_url_and_stores_a_recruit_page_row(): void
    {
        Queue::fake([AnalyzeRecruitPageJob::class]);
        Http::fake(['*/careers' => Http::response('<html><body>採用情報</body></html>', 200, ['Content-Type' => 'text/html'])]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();

        (new FetchRecruitPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id, 'https://example.com/careers'))
            ->handle(app(AnalysisPipeline::class));

        Http::assertSent(fn ($request) => $request->url() === 'https://example.com/careers');

        $page = AnalysisPage::query()->where('website_analysis_id', $websiteAnalysis->id)->where('page_type', PageType::Recruit)->first();
        $this->assertNotNull($page);
        $this->assertSame(200, $page->http_status);

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::FetchRecruitPage)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        Queue::assertPushed(AnalyzeRecruitPageJob::class);
    }

    public function test_a_fetch_failure_still_dispatches_the_next_job_and_marks_this_job_failed(): void
    {
        Queue::fake([AnalyzeRecruitPageJob::class]);
        Http::fake(['*/careers' => Http::response([], 500)]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();

        (new FetchRecruitPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id, 'https://example.com/careers'))
            ->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::FetchRecruitPage)->first();
        $this->assertSame(AnalysisJobStatus::Failed, $job->status);

        // 採用ページが見つかったのに取得に失敗した場合も、後続(評価不可を
        // 記録する)は必ず起動する。
        Queue::assertPushed(AnalyzeRecruitPageJob::class);
    }
}
