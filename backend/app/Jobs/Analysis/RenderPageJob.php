<?php

namespace App\Jobs\Analysis;

use App\Enums\JobType;
use App\Enums\MetricResultStatus;
use App\Enums\PageType;
use App\Exceptions\Analysis\AnalysisException;
use App\Jobs\Analysis\Concerns\RecordsMetricResults;
use App\Jobs\Analysis\Concerns\WritesAnalysisStorage;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\Analysis\AnalyzerClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * analyzer(Playwright)によるレンダリング後HTMLの取得。
 * DetectTechnologyJobがJS実行後のDOMを利用できるよう、レンダリング結果を
 * 保存しておく(取得できなかった場合、DetectTechnologyJobは静的HTMLに
 * フォールバックする)。
 *
 * 「固定表示CTA」の有無(position: fixed/sticky)はレンダリング後のCSS適用
 * 結果でしか判定できないため、静的HTML解析(AnalyzeHtmlSeoJob)ではなく
 * ここでanalyzerの検出結果をそのままMetricResultとして記録する。
 *
 * AnalyzeHtmlSeoJobは(FetchStaticPageJobの完了直後に起動されるため)通常
 * このジョブより先に完了し、静的HTMLで一度だけSNS/価格導線/H1等を解析・
 * 確定してしまう。JS描画コンテンツを取りこぼさないよう、このジョブの
 * 終端(成功・失敗いずれも)からReanalyzeRenderedHtmlJobを必ず起動し、
 * レンダリング済みHTMLが利用可能ならそれで再解析する
 * (onWebsiteJobTerminal参照。process()自身のtry/finallyで行わない理由は
 * BaseWebsiteAnalysisJob::onWebsiteJobTerminal()のdocblockを参照)。
 *
 * ブランド・ホイール(6軸)分析(GenerateBrandWheelAnalysisJob)も同じ終端
 * フックから起動する(2026-08-04) ―― 以前はdispatchWebsiteFanOut()から
 * このジョブと並列に起動していたが、レンダリング後HTMLがまだ無い状態で
 * 静的HTMLだけを読んで判定してしまう競合が本番で確認されたため、
 * このジョブの完了後(成功・失敗いずれでも)に必ず起動する形へ変更した。
 */
class RenderPageJob extends BaseWebsiteAnalysisJob
{
    use RecordsMetricResults, WritesAnalysisStorage;

    public $tries = 2;

    // AnalyzerClient::render()のHTTP timeout(90秒)と同値だと、analyzerが
    // 応答をハングさせた場合にLaravelのHTTP timeoutとLaravelキュー基盤の
    // ジョブtimeout(pcntl_alarm)が競合し、ジョブtimeoutが先に発火すると
    // handle()のtry/catchを経由せずWorkerプロセスごと強制終了され得る
    // (RunLighthouseJobで実際に発生した障害と同じクラスの不具合)。
    // 30秒以上のマージンを保つ。
    public $timeout = 120;

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

        /** @var AnalysisStoragePaths $paths */
        $paths = app(AnalysisStoragePaths::class);
        $htmlPath = $paths->rawHtmlPath($this->analysisId, $this->websiteAnalysisId, 'homepage.rendered.html');

        try {
            $data = $client->render($website->normalized_url);
            $this->putToAnalysisStorage($htmlPath, (string) ($data['html'] ?? ''));
        } catch (AnalysisException $e) {
            $this->recordMetric($this->websiteAnalysisId, 'fixed_cta_present', MetricResultStatus::Unavailable, errorCode: $e->errorCode->value, errorMessage: $e->getMessage());

            throw $e;
        }

        AnalysisPage::query()->updateOrCreate(
            ['website_analysis_id' => $this->websiteAnalysisId, 'page_type' => PageType::Homepage],
            ['rendered_html_path' => $htmlPath],
        );

        // 2026-08-19追加: analysis_id=45/website_analysis_id=93の障害調査用の
        // 一時的な診断ログ。FetchRecruitPageJobと同じ理由(書き込み時の
        // hostname・exists()結果を後段の診断ログと突き合わせるため)。
        Log::info('Render page: rendered html saved', [
            'analysis_id' => $this->analysisId,
            'website_analysis_id' => $this->websiteAnalysisId,
            'hostname' => gethostname(),
            'rendered_html_path' => $htmlPath,
            'exists_immediately_after_write' => Storage::disk('analysis')->exists($htmlPath),
        ]);

        // analyzerがpage.gotoのtimeout後もDOM取得済みとして部分成功
        // (navigation_status=partial)を返すことがある。Job自体は失敗
        // ではない(HTMLは取得できている)ため成功として扱うが、原因調査
        // できるようwarning logのみ残す。
        if (($data['navigation_status'] ?? 'ok') === 'partial') {
            Log::warning('RenderPageJob received a partial navigation result from analyzer', [
                'analysis_id' => $this->analysisId,
                'website_analysis_id' => $this->websiteAnalysisId,
                'warning' => $data['warning'] ?? null,
            ]);
        }

        $fixedCta = $data['fixed_cta'] ?? null;
        $detected = (bool) ($fixedCta['detected'] ?? false);

        $this->recordMetric(
            $this->websiteAnalysisId,
            'fixed_cta_present',
            $detected ? MetricResultStatus::Success : MetricResultStatus::NotFound,
            normalizedValue: $detected,
            rawValue: $fixedCta,
            evidence: [
                'navigation_status' => $data['navigation_status'] ?? 'ok',
                'navigation_warning' => $data['warning'] ?? null,
            ],
        );
    }

    protected function onWebsiteJobTerminal(AnalysisPipeline $pipeline): void
    {
        $pipeline->dispatchReanalysis($this->analysisId, $this->websiteAnalysisId);
        $pipeline->dispatchBrandWheelAnalysisIfDue($this->analysisId, $this->websiteAnalysisId);
        $pipeline->dispatchNextAnalyzerJob($this->analysisId, $this->websiteAnalysisId, $this->jobType());
    }
}
