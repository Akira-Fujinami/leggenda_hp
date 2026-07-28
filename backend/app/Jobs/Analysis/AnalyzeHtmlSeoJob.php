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
 * FetchStaticPageJobが保存した生HTMLを解析し、technical_seo/content/
 * accessibility/conversionカテゴリのMetricResultを記録する一次解析。
 *
 * 依存関係: FetchStaticPageJobの成否に関わらず(onWebsiteJobTerminal経由で)
 * 必ず起動される。HTMLが取得できていない場合、全指標をunavailableで記録した上で
 * AnalysisErrorCode::DependencyUnavailableとしてこのJob自身もFailedにする
 * (retryはしない ―― $tries=1、かつDependencyUnavailableはisRetryable()=false)。
 * これによりAnalysisJob.error_codeを見るだけで「解析対象のHTMLが無かった」
 * ことが分かり、「静的解析はしたが何も見つからなかった」場合(Completed)と
 * 区別できる。取得できなかった元の原因(接続timeout等)はFetchStaticPageJob側の
 * AnalysisJobに既に記録されているため、ここで上書きはしない。
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

            throw new AnalysisException(AnalysisErrorCode::DependencyUnavailable, '解析対象のHTMLが取得できなかったため、解析をスキップしました。');
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

    /**
     * Phase 3: recruit_link_present(このJobが今まさに記録した、business_links.recruitの
     * 検出結果)を使って採用ページを取得する後続Jobを必ず起動する。process()が
     * DependencyUnavailableで例外を投げて終了した場合(HTML自体が無かった場合)も
     * 含め、失敗時にもここは呼ばれる(BaseWebsiteAnalysisJob::handle()参照) ――
     * その場合はrecruit_link_presentの行自体が存在しないため、
     * FetchRecruitPageJob側でURLがnullとして扱われ、正常にno-op終端する。
     */
    protected function onWebsiteJobTerminal(AnalysisPipeline $pipeline): void
    {
        $pipeline->dispatchRecruitPageFetch($this->analysisId, $this->websiteAnalysisId);
    }
}
