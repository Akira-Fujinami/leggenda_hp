<?php

namespace App\Jobs\Analysis;

use App\Enums\JobType;
use App\Enums\PageType;
use App\Jobs\Analysis\Concerns\WritesAnalysisStorage;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\Analysis\SafeHttpFetcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * トップページのbusiness_links.recruit(HtmlSeoAnalyzer::analyzeBusinessLinks()が
 * 既に検出済み)が指す採用ページを取得して保存する。FetchStaticPageJobの採用
 * ページ版で、AnalyzeRecruitPageJob(このJobが保存したHTMLに依存)を必ず
 * (成功・失敗によらず)後続起動する。
 *
 * $recruitUrlは呼び出し元(AnalysisPipeline::resolveRecruitUrl())が
 * 既に絶対URLへ解決済みのものを渡す(相対hrefの解決はパイプライン側の
 * 責務に一本化し、このJob自身では行わない)。
 *
 * トップページに採用ページへのリンクが見つからなかった場合
 * ($recruitUrlがnull)は、実際のHTTP取得を一切行わずに正常終了する
 * ―― これは技術的な失敗ではなく「採用ページが無い」という正当な状態であり、
 * その旨はリード向け表示側(LeadPerspectiveComposer)が
 * recruit_link_present(トップページ側の指標)を見て「計測対象外」として
 * 判定する。一方、リンクは見つかったが実際の取得に失敗した場合は
 * AnalysisException経由でこのJob自身がfailedになり、後続の
 * AnalyzeRecruitPageJob/RunRecruitLighthouseJobが「評価不可」相当の
 * 結果を記録する(採用ページを検出できたのに内容を確認できなかった、
 * という区別をリードに正直に伝えるため)。
 *
 * 取得には既存のSafeHttpFetcher(SafeUrlValidator経由のSSRF検証込み)を
 * そのまま使う ―― 新たな取得経路を作らない。
 */
class FetchRecruitPageJob extends BaseWebsiteAnalysisJob
{
    use WritesAnalysisStorage;

    public $tries = 2;

    public $timeout = 30;

    public function __construct(
        int $analysisId,
        int $websiteAnalysisId,
        public readonly ?string $recruitUrl,
    ) {
        parent::__construct($analysisId, $websiteAnalysisId);
    }

    public function jobType(): JobType
    {
        return JobType::FetchRecruitPage;
    }

    protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void
    {
        if ($this->recruitUrl === null || $this->recruitUrl === '') {
            return;
        }

        /** @var SafeHttpFetcher $fetcher */
        $fetcher = app(SafeHttpFetcher::class);

        // SafeUrlValidatorが拒否するURL(SSRF対象・不正スキーム等)であっても、
        // このJob自体は「取得できなかった」として正常にfailed終端させる
        // (リトライ可能なエラーではないため、AnalysisException::isRetryable()の
        // 判定にそのまま従う)。
        $result = $fetcher->fetch($this->recruitUrl, ['text/html', 'application/xhtml+xml']);

        /** @var AnalysisStoragePaths $paths */
        $paths = app(AnalysisStoragePaths::class);
        $htmlPath = $paths->rawHtmlPath($this->analysisId, $this->websiteAnalysisId, 'recruit.html');
        $this->putToAnalysisStorage($htmlPath, $result->body);

        AnalysisPage::query()->updateOrCreate(
            ['website_analysis_id' => $this->websiteAnalysisId, 'page_type' => PageType::Recruit],
            [
                'url' => $result->requestedUrl,
                'final_url' => $result->finalUrl,
                'http_status' => $result->httpStatus,
                'content_type' => $result->contentType,
                'raw_html_path' => $htmlPath,
                'fetched_at' => now(),
            ],
        );

        // 2026-08-19追加: analysis_id=45/website_analysis_id=93の障害調査用の
        // 一時的な診断ログ。書き込み直後にこのプロセスから見えているhostname・
        // 保存パス・exists()結果を記録し、後段(GenerateBrandWheelAnalysisJob)の
        // 診断ログと突き合わせられるようにする。
        Log::info('Fetch recruit page: html saved', [
            'analysis_id' => $this->analysisId,
            'website_analysis_id' => $this->websiteAnalysisId,
            'hostname' => gethostname(),
            'saved_path' => $htmlPath,
            'exists_immediately_after_write' => Storage::disk('analysis')->exists($htmlPath),
        ]);
    }

    protected function onWebsiteJobTerminal(AnalysisPipeline $pipeline): void
    {
        $pipeline->dispatchRecruitPageAnalysis($this->analysisId, $this->websiteAnalysisId);
    }
}
