<?php

namespace App\Jobs\Analysis;

use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Enums\PageType;
use App\Jobs\Analysis\Concerns\RecordsMetricResults;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\Analysis\AnalyzerClient;
use Illuminate\Support\Facades\Storage;

/**
 * analyzer(Playwright)によるレンダリング後HTMLの取得。
 * DetectTechnologyJobがJS実行後のDOMを利用できるよう、レンダリング結果を
 * 保存しておく(取得できなかった場合、DetectTechnologyJobは静的HTMLに
 * フォールバックする)。
 *
 * 「固定表示CTA」の有無(position: fixed/sticky)はレンダリング後のCSS適用
 * 結果でしか判定できないため、静的HTML解析(AnalyzeHtmlSeoJob)ではなく
 * ここでanalyzerの検出結果をそのままMetricResultとして記録する。
 */
class RenderPageJob extends BaseWebsiteAnalysisJob
{
    use RecordsMetricResults;

    public $tries = 2;

    public $timeout = 90;

    public $backoff = [15, 45];

    public function jobType(): JobType
    {
        return JobType::RenderPage;
    }

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        $website = $websiteAnalysis->website;

        /** @var AnalyzerClient $client */
        $client = app(AnalyzerClient::class);
        $data = $client->render($website->normalized_url);

        /** @var AnalysisStoragePaths $paths */
        $paths = app(AnalysisStoragePaths::class);
        $htmlPath = $paths->rawHtmlPath($this->analysisId, $this->websiteAnalysisId, 'homepage.rendered.html');
        Storage::disk('analysis')->put($htmlPath, (string) ($data['html'] ?? ''));

        AnalysisPage::query()->updateOrCreate(
            ['website_analysis_id' => $this->websiteAnalysisId, 'page_type' => PageType::Homepage],
            ['rendered_html_path' => $htmlPath],
        );

        $fixedCta = $data['fixed_cta'] ?? null;
        $detected = (bool) ($fixedCta['detected'] ?? false);

        $this->recordMetric(
            $this->websiteAnalysisId,
            'fixed_cta_present',
            $detected ? MetricResultStatus::Success : MetricResultStatus::NotFound,
            normalizedValue: $detected,
            rawValue: $fixedCta,
        );
    }
}
