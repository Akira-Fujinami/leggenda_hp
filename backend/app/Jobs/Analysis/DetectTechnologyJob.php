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
use App\Services\Analysis\AnalyzerClient;
use Illuminate\Support\Facades\Storage;

/**
 * 使用技術の検出。可能ならRenderPageJobのレンダリング後HTML、無ければ
 * FetchStaticPageJobの静的HTMLをanalyzerへのヒントとして渡す
 * (どちらも無い場合はURLのみで呼び出し、analyzer側で取得させる)。
 */
class DetectTechnologyJob extends BaseWebsiteAnalysisJob
{
    use RecordsMetricResults;

    public $tries = 2;

    public $timeout = 60;

    public $backoff = [10, 30];

    public function jobType(): JobType
    {
        return JobType::DetectTechnology;
    }

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        $website = $websiteAnalysis->website;

        $page = AnalysisPage::query()
            ->where('website_analysis_id', $this->websiteAnalysisId)
            ->where('page_type', PageType::Homepage)
            ->first();

        $html = null;
        $disk = Storage::disk('analysis');
        if ($page?->rendered_html_path !== null && $disk->exists($page->rendered_html_path)) {
            $html = $disk->get($page->rendered_html_path);
        } elseif ($page?->raw_html_path !== null && $disk->exists($page->raw_html_path)) {
            $html = $disk->get($page->raw_html_path);
        }

        /** @var AnalyzerClient $client */
        $client = app(AnalyzerClient::class);
        $data = $client->technology($website->normalized_url, $html);

        $technologies = $data['technologies'] ?? [];
        $detected = count($technologies) > 0;

        $this->recordMetric(
            $this->websiteAnalysisId,
            'technology_detected',
            MetricResultStatus::Success,
            achievedRatio: $detected ? 1.0 : 0.0,
            rawValue: ['technologies' => $technologies],
            evidence: ['count' => count($technologies)],
        );
    }
}
