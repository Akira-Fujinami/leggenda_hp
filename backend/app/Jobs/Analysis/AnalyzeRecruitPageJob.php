<?php

namespace App\Jobs\Analysis;

use App\Enums\JobType;
use App\Enums\PageType;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\HtmlSeoAnalyzer;
use App\Services\Analysis\RecruitPageMetricRecorder;
use Illuminate\Support\Facades\Storage;

/**
 * FetchRecruitPageJobが保存した採用ページのHTMLを解析し、recruit_*の
 * MetricResultを記録する。検出ロジック自体はHtmlSeoAnalyzer::analyze()を
 * そのまま再利用する(新たな検出ロジックは書かない)。
 *
 * 採用ページが見つからなかった、または取得に失敗した場合(AnalysisPage行が
 * 無い)は、全recruit_*指標をunavailableとして記録した上で正常終了する
 * (AnalyzeHtmlSeoJobと異なりこのJob自体はfailedにしない ―― 「採用ページが
 * 無い」ことは技術的な失敗ではなく正当な状態であり、その区別は
 * LeadPerspectiveComposerがトップページ側のrecruit_link_presentを見て
 * 行う: recruit_link_present=falseなら「計測対象外」、true なのにこの
 * Jobがunavailableを記録していれば「評価不可」)。
 */
class AnalyzeRecruitPageJob extends BaseWebsiteAnalysisJob
{
    public $tries = 1;

    public $timeout = 20;

    public function jobType(): JobType
    {
        return JobType::AnalyzeRecruitPage;
    }

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        $recorder = app(RecruitPageMetricRecorder::class);

        $page = AnalysisPage::query()
            ->where('website_analysis_id', $this->websiteAnalysisId)
            ->where('page_type', PageType::Recruit)
            ->first();

        $disk = Storage::disk('analysis');

        if ($page?->raw_html_path === null || ! $disk->exists($page->raw_html_path)) {
            $recorder->recordAllUnavailable($this->websiteAnalysisId);

            return;
        }

        $html = $disk->get($page->raw_html_path);
        $pageUrl = $page->final_url ?? $page->url;

        try {
            $result = app(HtmlSeoAnalyzer::class)->analyze($html, $pageUrl);
        } catch (\Throwable $e) {
            $recorder->recordAllUnavailable($this->websiteAnalysisId);

            return;
        }

        $recorder->recordAll($this->websiteAnalysisId, $result, $page->id);
    }
}
