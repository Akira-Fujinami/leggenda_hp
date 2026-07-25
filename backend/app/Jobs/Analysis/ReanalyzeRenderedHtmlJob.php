<?php

namespace App\Jobs\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Enums\JobType;
use App\Enums\PageType;
use App\Exceptions\Analysis\AnalysisException;
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
 * RenderPageJobの終端(成功・失敗いずれも)から必ず起動される。レンダリング済み
 * HTMLが利用できない場合、静的結果(一次解析)はそのまま最終結果として残し
 * (全Metricをunavailable化しない ―― AnalyzeHtmlSeoJobが既に記録済み)、
 * このJob自身はAnalysisErrorCode::DependencyUnavailableとしてFailedにする。
 * こうすることで「JS描画後の再解析は行われなかった」ことをAnalysisJob.error_codeで
 * 判別できる(表示結果自体は静的解析のままで変わらない ―― RenderPageJobが
 * 失敗している場合、WebsiteAnalysisは既にrender_page側のFailedでpartial/failed
 * 判定に反映されているため、ここでもFailedにしても追加の悪化はない)。
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
            // 結果を最終結果として確定させ、Metricは一切上書きしない
            // (unavailable化もしない ―― AnalyzeHtmlSeoJobの結果が既に正)。
            throw new AnalysisException(AnalysisErrorCode::DependencyUnavailable, 'レンダリング済みHTMLが利用できなかったため、再解析をスキップしました。');
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
