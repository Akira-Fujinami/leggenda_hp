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
 * RenderPageJob完了後の二次解析。AnalyzeHtmlSeoJob(一次解析)が静的HTMLで
 * 暫定的に記録した結果を、レンダリング済みHTML(JS実行後)で上書きし、
 * JS描画コンテンツ(SNSリンク・価格付き商品カード・動的H1・チャットボット・
 * 動的フォーム等)を取りこぼさないようにする。
 *
 * RenderPageJobの終端(成功・失敗いずれも)から必ず起動されるため、
 * レンダリング済みHTMLが利用できない場合は例外を投げず静かにno-opする
 * (静的結果(一次解析)をそのまま最終結果として残す ―― 全Metricを
 * unavailable化してはいけない。RenderPageJob失敗時はこの経路で
 * fallback_used相当の状態になる。frontend表示はAnalysisResultsResource側で
 * RenderPage/ReanalyzeRenderedHtmlのAnalysisJobステータスから判定する)。
 *
 * $tries=1: AnalyzeHtmlSeoJobによる静的解析という安全網が既にあるため、
 * この二次解析自体の再試行は必須ではない。
 */
class ReanalyzeRenderedHtmlJob extends BaseWebsiteAnalysisJob
{
    public $tries = 1;

    public $timeout = 20;

    public function jobType(): JobType
    {
        return JobType::ReanalyzeRenderedHtml;
    }

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        $page = AnalysisPage::query()
            ->where('website_analysis_id', $this->websiteAnalysisId)
            ->where('page_type', PageType::Homepage)
            ->first();

        $disk = Storage::disk('analysis');

        if ($page?->rendered_html_path === null || ! $disk->exists($page->rendered_html_path)) {
            // レンダリング済みHTMLが利用不可(RenderPageJob失敗、または
            // analyzerが空のHTMLしか返さなかった等)。静的HTML解析(一次解析)の
            // 結果を最終結果として確定させ、ここでは何も上書きしない。
            return;
        }

        $html = $disk->get($page->rendered_html_path);
        $pageUrl = $page->final_url ?? $page->url;

        try {
            $result = app(HtmlSeoAnalyzer::class)->analyze($html, $pageUrl);
        } catch (\Throwable $e) {
            // 二次解析自体の失敗(レンダリング済みHTMLが壊れている等)は、
            // 静的解析(一次解析)の結果をそのまま最終結果として残す
            // (全Metricをunavailable/error化しない)。
            report($e);

            return;
        }
        $result['html_source'] = 'rendered';

        // renderedを最終権威としてページの主要フィールドも更新する。
        $page->update([
            'title' => $result['title']['text'],
            'meta_description' => $result['meta_description']['text'],
            'h1_count' => $result['h1']['count'],
            'word_count' => $result['content']['word_count'],
        ]);

        app(HtmlSeoMetricRecorder::class)->recordAll($this->websiteAnalysisId, $result, $page->id, 'rendered');
    }
}
