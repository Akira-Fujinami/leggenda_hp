<?php

namespace App\Jobs\Analysis;

use App\Enums\JobType;
use App\Enums\PageType;
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
 */
class RenderPageJob extends BaseWebsiteAnalysisJob
{
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
    }
}
