<?php

namespace App\Jobs\Analysis;

use App\Enums\JobType;
use App\Enums\PageType;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\HtmlSeoAnalyzer;
use App\Services\Analysis\HtmlSeoMetricRecorder;
use Illuminate\Support\Facades\Storage;

/**
 * FetchStaticPageJobが保存した生HTMLを解析し、technical_seo/content/
 * accessibility/conversionカテゴリのMetricResultを記録する一次解析。
 *
 * 依存関係: FetchStaticPageJobの成否に関わらず(onWebsiteJobTerminal経由で)
 * 必ず起動される。HTMLが取得できていない場合は「失敗」ではなく「測定不能」
 * として全指標をunavailableで記録し、正常終了する(取得できなかった原因は
 * FetchStaticPageJob側のAnalysisJobに既に記録されているため、ここで
 * 重複してエラー扱いにはしない)。
 *
 * このジョブは通常RenderPageJobより先に完了するため、多くの場合ここでは
 * 静的HTMLのみで解析する(=一次解析、暫定結果)。RenderPageJobが後から
 * 完了すると、ReanalyzeRenderedHtmlJob(別ジョブ)がレンダリング済みHTMLで
 * 再解析し、より優先度の高い結果(source=rendered)へ更新する
 * (RecordsMetricResults::recordMetric()のsource優先度ガード参照)。
 */
class AnalyzeHtmlSeoJob extends BaseWebsiteAnalysisJob
{
    public $tries = 1;

    public $timeout = 20;

    public function jobType(): JobType
    {
        return JobType::AnalyzeHtmlSeo;
    }

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        $recorder = app(HtmlSeoMetricRecorder::class);

        $page = AnalysisPage::query()
            ->where('website_analysis_id', $this->websiteAnalysisId)
            ->where('page_type', PageType::Homepage)
            ->first();

        $disk = Storage::disk('analysis');

        // レンダリング後HTML(JS実行後)が既に利用可能ならそちらを優先する
        // (H1/viewport等をJSで注入するSPA的なサイトでは、静的HTMLだけでは
        // 実際には存在する要素を「無し」と誤判定しかねないため)。
        // AnalyzeHtmlSeoJobはFetchStaticPageJobの完了直後に起動されるため、
        // RenderPageJob(別途並行実行)がまだ完了していないことも多く、
        // その場合は静的HTMLで暫定解析し、ReanalyzeRenderedHtmlJobによる
        // 再解析に委ねる。
        $htmlSource = null;
        $htmlPath = null;
        if ($page?->rendered_html_path !== null && $disk->exists($page->rendered_html_path)) {
            $htmlPath = $page->rendered_html_path;
            $htmlSource = 'rendered';
        } elseif ($page?->raw_html_path !== null && $disk->exists($page->raw_html_path)) {
            $htmlPath = $page->raw_html_path;
            $htmlSource = 'static';
        }

        if ($htmlPath === null) {
            $recorder->recordAllUnavailable($this->websiteAnalysisId);

            return;
        }

        $html = $disk->get($htmlPath);
        $pageUrl = $page->final_url ?? $page->url;

        try {
            $result = app(HtmlSeoAnalyzer::class)->analyze($html, $pageUrl);
        } catch (\Throwable $e) {
            // HTML自体は取得できているため「取得不能」(recordAllUnavailable)
            // ではなく「解析失敗」(recordAllError)として扱う。H1を含む全指標が
            // 個別にerror状態を持てるようにするための区別。
            $recorder->recordAllError($this->websiteAnalysisId, $e->getMessage());

            return;
        }
        $result['html_source'] = $htmlSource;

        $page->update([
            'title' => $result['title']['text'],
            'meta_description' => $result['meta_description']['text'],
            'h1_count' => $result['h1']['count'],
            'word_count' => $result['content']['word_count'],
        ]);

        $recorder->recordAll($this->websiteAnalysisId, $result, $page->id, $htmlSource);
    }
}
