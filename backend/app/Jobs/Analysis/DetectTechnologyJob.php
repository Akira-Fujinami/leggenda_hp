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
 *
 * 「技術の種類そのものへの優劣」はつけない方針のため、CMS/フレームワーク/
 * 計測ツールの個別検出結果はscoring_type=not_scored(採点対象外・情報表示専用)
 * として記録し、実際に採点対象となるのは「アクセス解析が設置されているか」
 * (analytics_configured)のみとする。
 */
class DetectTechnologyJob extends BaseWebsiteAnalysisJob
{
    use RecordsMetricResults;

    public $tries = 2;

    public $timeout = 60;

    public $backoff = [10, 30];

    private const INFORMATIONAL_MAP = [
        'ga_detected' => ['Google Analytics'],
        'gtm_detected' => ['Google Tag Manager'],
        'clarity_detected' => ['Microsoft Clarity'],
        'meta_pixel_detected' => ['Meta Pixel'],
        'recaptcha_detected' => ['reCAPTCHA'],
        'cdn_detected' => ['Cloudflare'],
    ];

    private const CMS_OR_FRAMEWORK_CATEGORIES = ['cms', 'framework'];

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
        $names = array_column($technologies, 'name');

        $analyticsConfigured = in_array('Google Analytics', $names, true) || in_array('Google Tag Manager', $names, true);
        $this->recordMetric(
            $this->websiteAnalysisId,
            'analytics_configured',
            MetricResultStatus::Success,
            normalizedValue: $analyticsConfigured,
            rawValue: ['technologies' => $technologies],
            evidence: ['count' => count($technologies)],
        );

        $cmsOrFramework = array_values(array_filter($technologies, fn ($t) => in_array($t['category'] ?? null, self::CMS_OR_FRAMEWORK_CATEGORIES, true)));
        $this->recordMetric(
            $this->websiteAnalysisId,
            'cms_detected',
            $cmsOrFramework === [] ? MetricResultStatus::NotFound : MetricResultStatus::Success,
            normalizedValue: $cmsOrFramework[0]['name'] ?? null,
            rawValue: ['detected' => $cmsOrFramework],
        );

        foreach (self::INFORMATIONAL_MAP as $key => $matchNames) {
            $detected = array_values(array_filter($technologies, fn ($t) => in_array($t['name'] ?? null, $matchNames, true)));
            $this->recordMetric(
                $this->websiteAnalysisId,
                $key,
                $detected === [] ? MetricResultStatus::NotFound : MetricResultStatus::Success,
                normalizedValue: $detected !== [],
                rawValue: ['detected' => $detected],
            );
        }
    }
}
