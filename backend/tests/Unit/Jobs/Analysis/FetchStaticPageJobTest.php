<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Enums\PageType;
use App\Jobs\Analysis\AnalyzeHtmlSeoJob;
use App\Jobs\Analysis\FetchStaticPageJob;
use App\Models\AnalysisPage;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FetchStaticPageJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
    }

    private function makeWebsiteAnalysis(): WebsiteAnalysis
    {
        $website = Website::factory()->create(['url' => 'https://example.com', 'normalized_url' => 'https://example.com']);

        return WebsiteAnalysis::factory()->create(['website_id' => $website->id]);
    }

    public function test_successful_fetch_stores_html_and_updates_website_analysis(): void
    {
        Queue::fake([AnalyzeHtmlSeoJob::class]);
        Http::fake([
            'https://example.com/' => Http::response('<html><title>t</title></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();

        (new FetchStaticPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::FetchStaticPage)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        $page = AnalysisPage::query()->where('website_analysis_id', $websiteAnalysis->id)->where('page_type', PageType::Homepage)->first();
        $this->assertNotNull($page);
        $this->assertSame(200, $page->http_status);
        Storage::disk('analysis')->assertExists($page->raw_html_path);

        $websiteAnalysis->refresh();
        $this->assertSame(200, $websiteAnalysis->http_status);

        Queue::assertPushed(AnalyzeHtmlSeoJob::class, fn ($j) => $j->websiteAnalysisId === $websiteAnalysis->id);
    }

    public function test_failed_fetch_still_dispatches_analyze_html_seo_job(): void
    {
        Queue::fake([AnalyzeHtmlSeoJob::class]);

        $website = Website::factory()->create(['url' => 'https://localhost', 'normalized_url' => 'https://localhost']);
        $websiteAnalysis = WebsiteAnalysis::factory()->create(['website_id' => $website->id]);

        (new FetchStaticPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::FetchStaticPage)->first();
        $this->assertSame(AnalysisJobStatus::Failed, $job->status);
        $this->assertSame(AnalysisErrorCode::UnsafeUrl->value, $job->error_code);

        Queue::assertPushed(AnalyzeHtmlSeoJob::class);
    }

    public function test_analyze_html_seo_job_is_not_dispatched_prematurely_during_a_retryable_attempt(): void
    {
        // 設計検証で発見した既存バグの回帰テスト: SafeHttpFetcherが
        // リトライ可能な例外(接続失敗=ConnectionTimeout)を投げ、attempt 1が
        // release()(再試行)される場合、AnalyzeHtmlSeoJobはまだdispatchされて
        // はいけない ―― まだHTMLが保存されていないため、早すぎるdispatchは
        // no-op相当の結果を記録して終端化してしまい、attempt 2が実際に
        // 成功してもその結果が反映されないまま放置される。
        Queue::fake([AnalyzeHtmlSeoJob::class]);
        // SafeHttpFetcherはwebsite.normalized_url("https://example.com"、末尾
        // スラッシュなし)をそのままリクエストするため、fakeのキーもそれに
        // 正確に一致させる(末尾スラッシュ付きのパターンは一致せず、意図せず
        // 実ネットワークへ素通りしてしまう)。
        Http::fake([
            'https://example.com' => Http::sequence()
                ->pushFailedConnection('simulated connection timeout')
                ->push('<html><title>t</title></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();

        $job = new FetchStaticPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id);
        $job->withFakeQueueInteractions();

        // attempt 1: 接続失敗(リトライ可能)→release()されるのみ。
        $job->handle(app(AnalysisPipeline::class));

        $job->assertReleased();
        Queue::assertNotPushed(AnalyzeHtmlSeoJob::class);

        $recordAfterAttempt1 = $websiteAnalysis->jobs()->where('job_type', JobType::FetchStaticPage)->first();
        $this->assertSame(AnalysisJobStatus::Running, $recordAfterAttempt1->status);

        // attempt 2: 実際のリトライを模して、$job(モック)を持たない新しい
        // インスタンスでhandle()を呼ぶ(sequenceの2番目のレスポンス=成功が返る)。
        $retryJob = new FetchStaticPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id);
        $retryJob->handle(app(AnalysisPipeline::class));

        $recordAfterAttempt2 = $websiteAnalysis->jobs()->where('job_type', JobType::FetchStaticPage)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $recordAfterAttempt2->status);

        // attempt2(実際に成功した試行)の完了後にのみ、正確に1回dispatchされる。
        Queue::assertPushed(AnalyzeHtmlSeoJob::class, 1);
    }

    public function test_rerunning_a_completed_job_is_a_noop(): void
    {
        Queue::fake([AnalyzeHtmlSeoJob::class]);
        Http::fake([
            'https://example.com/' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $websiteAnalysis = $this->makeWebsiteAnalysis();
        $pipeline = app(AnalysisPipeline::class);

        (new FetchStaticPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle($pipeline);
        (new FetchStaticPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle($pipeline);

        Http::assertSentCount(1);
    }
}
