<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Enums\AnalysisJobStatus;
use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Enums\PageType;
use App\Jobs\Analysis\AnalyzeRecruitPageJob;
use App\Models\AnalysisPage;
use App\Models\MetricResult;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\Analysis\RecruitPageMetricRecorder;
use Database\Seeders\CategoryDefinitionSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnalyzeRecruitPageJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategoryDefinitionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);
        Storage::fake('analysis');
    }

    private function resultFor(int $websiteAnalysisId, string $key): ?MetricResult
    {
        return MetricResult::query()
            ->whereHas('metricDefinition', fn ($q) => $q->where('key', $key))
            ->where('website_analysis_id', $websiteAnalysisId)
            ->first();
    }

    public function test_it_marks_all_recruit_metrics_unavailable_when_no_recruit_page_was_fetched(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        (new AnalyzeRecruitPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::AnalyzeRecruitPage)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        foreach (RecruitPageMetricRecorder::ALL_KEYS as $key) {
            $this->assertSame(MetricResultStatus::Unavailable, $this->resultFor($websiteAnalysis->id, $key)?->status, "key={$key}");
        }
    }

    public function test_it_records_real_metrics_from_the_fetched_recruit_page_html(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        $html = '<html><head><title>採用情報 | Example</title>'
            .'<meta name="description" content="採用に関するご案内"><meta name="viewport" content="width=device-width">'
            .'</head><body><h1>採用情報</h1>'
            .'<p>'.str_repeat('募集要項の説明 ', 100).'</p>'
            .'<a href="/faq">よくある質問</a>'
            .'<a href="tel:0312345678">電話する</a>'
            .'<form><input type="email" required><input type="submit"></form>'
            .'</body></html>';

        $rawHtmlPath = app(AnalysisStoragePaths::class)->rawHtmlPath($websiteAnalysis->analysis_id, $websiteAnalysis->id, 'recruit.html');
        Storage::disk('analysis')->put($rawHtmlPath, $html);

        AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com/careers',
            'final_url' => 'https://example.com/careers',
            'page_type' => PageType::Recruit,
            'http_status' => 200,
            'raw_html_path' => $rawHtmlPath,
            'fetched_at' => now(),
        ]);

        (new AnalyzeRecruitPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        $job = $websiteAnalysis->jobs()->where('job_type', JobType::AnalyzeRecruitPage)->first();
        $this->assertSame(AnalysisJobStatus::Completed, $job->status);

        $this->assertSame(MetricResultStatus::Success, $this->resultFor($websiteAnalysis->id, 'recruit_title_present')->status);
        $this->assertTrue($this->resultFor($websiteAnalysis->id, 'recruit_title_present')->normalized_value['value']);

        $this->assertSame(MetricResultStatus::Success, $this->resultFor($websiteAnalysis->id, 'recruit_meta_description_present')->status);
        $this->assertSame(MetricResultStatus::Success, $this->resultFor($websiteAnalysis->id, 'recruit_h1_single')->status);
        $this->assertTrue($this->resultFor($websiteAnalysis->id, 'recruit_h1_single')->normalized_value['value']);

        $this->assertSame(MetricResultStatus::Success, $this->resultFor($websiteAnalysis->id, 'recruit_faq_link_present')->status);
        $this->assertTrue($this->resultFor($websiteAnalysis->id, 'recruit_faq_link_present')->normalized_value['value']);

        $this->assertSame(MetricResultStatus::Success, $this->resultFor($websiteAnalysis->id, 'recruit_tel_or_mailto_present')->status);
        $this->assertTrue($this->resultFor($websiteAnalysis->id, 'recruit_tel_or_mailto_present')->normalized_value['value']);

        $this->assertSame(MetricResultStatus::Success, $this->resultFor($websiteAnalysis->id, 'recruit_form_present')->status);
        $this->assertTrue($this->resultFor($websiteAnalysis->id, 'recruit_form_present')->normalized_value['value']);

        $this->assertSame(MetricResultStatus::Success, $this->resultFor($websiteAnalysis->id, 'recruit_viewport_present')->status);
        $this->assertTrue($this->resultFor($websiteAnalysis->id, 'recruit_viewport_present')->normalized_value['value']);
    }

    /**
     * recruit_*はnot_scoredのため、これらの指標が記録されても既存カテゴリの
     * 配点合計(100点)には一切影響しないことを回帰確認する。
     */
    public function test_recruit_metrics_never_contribute_to_the_scoring_system(): void
    {
        $websiteAnalysis = WebsiteAnalysis::factory()->create();

        $html = '<html><head><title>採用情報</title></head><body><h1>採用情報</h1></body></html>';
        $rawHtmlPath = app(AnalysisStoragePaths::class)->rawHtmlPath($websiteAnalysis->analysis_id, $websiteAnalysis->id, 'recruit.html');
        Storage::disk('analysis')->put($rawHtmlPath, $html);

        AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com/careers',
            'final_url' => 'https://example.com/careers',
            'page_type' => PageType::Recruit,
            'http_status' => 200,
            'raw_html_path' => $rawHtmlPath,
            'fetched_at' => now(),
        ]);

        (new AnalyzeRecruitPageJob($websiteAnalysis->analysis_id, $websiteAnalysis->id))->handle(app(AnalysisPipeline::class));

        foreach (RecruitPageMetricRecorder::ALL_KEYS as $key) {
            $result = $this->resultFor($websiteAnalysis->id, $key);
            $this->assertSame(0.0, (float) $result->metricDefinition->max_score, "key={$key} should carry zero weight");
        }
    }
}
