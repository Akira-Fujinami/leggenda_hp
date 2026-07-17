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
use App\Services\Analysis\HtmlSeoAnalyzer;
use Illuminate\Support\Facades\Storage;

/**
 * FetchStaticPageJobが保存した生HTMLを解析し、technical_seo/contentカテゴリの
 * MetricResultを記録する。
 *
 * 依存関係: FetchStaticPageJobの成否に関わらず(finally句で)必ず起動される。
 * HTMLが取得できていない場合は「失敗」ではなく「測定不能」として全指標を
 * unavailableで記録し、正常終了する(取得できなかった原因はFetchStaticPageJob
 * 側のAnalysisJobに既に記録されているため、ここで重複してエラー扱いにはしない)。
 */
class AnalyzeHtmlSeoJob extends BaseWebsiteAnalysisJob
{
    use RecordsMetricResults;

    public $tries = 1;

    public $timeout = 20;

    private const WORD_COUNT_TARGET = 300;

    public function jobType(): JobType
    {
        return JobType::AnalyzeHtmlSeo;
    }

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        $page = AnalysisPage::query()
            ->where('website_analysis_id', $this->websiteAnalysisId)
            ->where('page_type', PageType::Homepage)
            ->first();

        $htmlPath = $page?->raw_html_path;

        if ($htmlPath === null || ! Storage::disk('analysis')->exists($htmlPath)) {
            $this->recordAllUnavailable();

            return;
        }

        $html = Storage::disk('analysis')->get($htmlPath);
        $pageUrl = $page->final_url ?? $page->url;

        $result = app(HtmlSeoAnalyzer::class)->analyze($html, $pageUrl);

        $page->update([
            'title' => $result['title']['text'],
            'meta_description' => $result['meta_description']['text'],
            'h1_count' => $result['h1']['count'],
            'word_count' => $result['content']['word_count'],
        ]);

        $this->recordMetric(
            $this->websiteAnalysisId, 'title_present', MetricResultStatus::Success,
            achievedRatio: $result['title']['present'] ? 1.0 : 0.0,
            rawValue: $result['title'], analysisPageId: $page->id,
        );

        $this->recordMetric(
            $this->websiteAnalysisId, 'meta_description_present', MetricResultStatus::Success,
            achievedRatio: $result['meta_description']['present'] ? 1.0 : 0.0,
            rawValue: $result['meta_description'], analysisPageId: $page->id,
        );

        $this->recordMetric(
            $this->websiteAnalysisId, 'h1_single', MetricResultStatus::Success,
            achievedRatio: $result['h1']['count'] === 1 ? 1.0 : 0.0,
            rawValue: $result['h1'], analysisPageId: $page->id,
        );

        $this->recordMetric(
            $this->websiteAnalysisId, 'canonical_present', MetricResultStatus::Success,
            achievedRatio: $result['canonical']['present'] ? 1.0 : 0.0,
            rawValue: $result['canonical'], analysisPageId: $page->id,
        );

        $this->recordMetric(
            $this->websiteAnalysisId, 'https', MetricResultStatus::Success,
            achievedRatio: parse_url($pageUrl, PHP_URL_SCHEME) === 'https' ? 1.0 : 0.0,
            rawValue: ['url' => $pageUrl], analysisPageId: $page->id,
        );

        $this->recordMetric(
            $this->websiteAnalysisId, 'viewport_present', MetricResultStatus::Success,
            achievedRatio: $result['content']['viewport_present'] ? 1.0 : 0.0,
            rawValue: $result['content'], analysisPageId: $page->id,
        );

        $altCoverage = $result['images']['alt_coverage'];
        $this->recordMetric(
            $this->websiteAnalysisId, 'img_alt_coverage', MetricResultStatus::Success,
            achievedRatio: $altCoverage ?? 1.0,
            rawValue: $result['images'], analysisPageId: $page->id,
        );

        $wordCount = $result['content']['word_count'];
        $this->recordMetric(
            $this->websiteAnalysisId, 'word_count_sufficient', MetricResultStatus::Success,
            achievedRatio: min(1.0, $wordCount / self::WORD_COUNT_TARGET),
            rawValue: ['word_count' => $wordCount, 'target' => self::WORD_COUNT_TARGET], analysisPageId: $page->id,
        );

        // ogp/structured_data/links/forms は現時点ではスコアに影響しない参考情報として
        // evidenceのみ保持する (将来カテゴリに組み込む際の土台)。
        $this->recordMetric(
            $this->websiteAnalysisId, 'og_and_structured_data_present', MetricResultStatus::Success,
            achievedRatio: (($result['ogp']['title'] ?? null) !== null || $result['structured_data']['count'] > 0) ? 1.0 : 0.0,
            rawValue: ['ogp' => $result['ogp'], 'structured_data' => $result['structured_data']],
            analysisPageId: $page->id,
        );
    }

    private function recordAllUnavailable(): void
    {
        foreach ([
            'title_present', 'meta_description_present', 'h1_single', 'canonical_present', 'https',
            'viewport_present', 'img_alt_coverage', 'word_count_sufficient', 'og_and_structured_data_present',
        ] as $key) {
            $this->recordMetric($this->websiteAnalysisId, $key, MetricResultStatus::Unavailable);
        }
    }
}
