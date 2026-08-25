<?php

namespace App\Jobs\Analysis;

use App\Exceptions\Analysis\AnalysisException;
use App\Jobs\Analysis\Concerns\WritesAnalysisStorage;
use App\Models\AnalysisCrawledPage;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\Analysis\AnalyzerClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 条件付きレンダリング(依頼D-4)。静的本文が乏しい巡回ページ(事前に
 * CrawlWebsitePageJob::finalizeCrawl()がrender_candidate=trueへ選定済み)を、
 * 1枚ずつ直列でレンダリングする。全ページの無条件レンダリングは行わない
 * (禁止事項)。
 *
 * 既存のRenderPageJobは流用しない ―― 書き込み先がanalysis_pages
 * (page_type=Homepage固定)であり、クロールページの結果でトップページの
 * 行を上書きしてしまうため(依頼者指摘)。AnalyzerClient::render()を
 * このJobから直接呼ぶ。
 *
 * 「1枚ずつ直列」は、次のジョブをこのジョブの終端(handle()の最後、または
 * failed())からのみdispatchする連鎖構造そのもので保証する ―― 本番は
 * キューワーカーが1本のため、同時に2枚がレンダリングされることは無い。
 *
 * analysis-heavyキュー(既存のRenderPageJob/RunLighthouseJob等、Analyzerを
 * 呼ぶ重い処理と同じ)で処理する。
 */
class RenderCrawledPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesAnalysisStorage;

    public $tries = 1;

    // AnalyzerClient::RENDER_TIMEOUT_SECONDS(90)+30秒のマージン
    // (RenderPageJobと同じ考え方 ―― analyzerがハングした場合、このJobの
    // $timeoutより先にHTTP timeoutが発火するようにする)。
    public $timeout = 120;

    public function __construct(
        public readonly int $analysisId,
        public readonly int $websiteAnalysisId,
        // 依頼M-1: finalizeCrawl()が選定した候補の総数(依頼D-4のcrawl_render_
        // candidate_max_count以下)。連鎖の各ジョブへそのまま引き継ぎ、
        // 「残り件数/総数」で進捗(0-99)を算出する。DBには候補選定時点の
        // スナップショットが残らない(render_candidate=trueは処理済みに
        // なると単純にfalseへ戻るだけで、失敗による解除と「元々候補で
        // なかった」行を区別できない)ため、コンストラクタ引数として運ぶ
        // 方式にした。nullの場合(既存呼び出し元・テストとの互換用)は
        // 進捗更新自体を行わない。
        public readonly ?int $totalCandidates = null,
    ) {}

    public function handle(AnalysisPipeline $pipeline, AnalyzerClient $client, AnalysisStoragePaths $paths): void
    {
        $next = AnalysisCrawledPage::query()
            ->where('website_analysis_id', $this->websiteAnalysisId)
            ->where('render_candidate', true)
            ->orderBy('depth')
            ->orderBy('id')
            ->first();

        if ($next === null) {
            $pipeline->dispatchBrandWheelAnalysisAfterCrawl($this->analysisId, $this->websiteAnalysisId);

            return;
        }

        $url = $next->final_url ?? $next->url;

        try {
            $data = $client->render($url);
            $html = (string) ($data['html'] ?? '');

            if ($html !== '') {
                $renderedPath = $paths->rawHtmlPath($this->analysisId, $this->websiteAnalysisId, "crawl/{$next->url_hash}.rendered.html");
                $this->putToAnalysisStorage($renderedPath, $html);
                $next->update(['rendered_html_path' => $renderedPath, 'render_candidate' => false]);
            } else {
                $next->update(['render_candidate' => false]);
            }
        } catch (AnalysisException $e) {
            Log::warning('RenderCrawledPageJob: render failed for a candidate page, falling back to static HTML', [
                'analysis_id' => $this->analysisId,
                'website_analysis_id' => $this->websiteAnalysisId,
                'analysis_crawled_page_id' => $next->id,
                'error_code' => $e->errorCode->value,
                'error_message' => $e->getMessage(),
            ]);
            $next->update(['render_candidate' => false]);
        }

        if ($this->totalCandidates !== null && $this->totalCandidates > 0) {
            $remaining = AnalysisCrawledPage::query()
                ->where('website_analysis_id', $this->websiteAnalysisId)
                ->where('render_candidate', true)
                ->count();
            $processed = max(0, $this->totalCandidates - $remaining);
            $progress = (int) round(100 * $processed / $this->totalCandidates);

            $pipeline->updateCrawlProgress(
                $this->analysisId,
                $this->websiteAnalysisId,
                \App\Enums\JobType::RenderCrawledPages,
                $progress,
            );
        }

        self::dispatch($this->analysisId, $this->websiteAnalysisId, $this->totalCandidates)->onQueue('analysis-heavy');
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);
        Log::error('RenderCrawledPageJob failed unexpectedly, skipping remaining render candidates', [
            'analysis_id' => $this->analysisId,
            'website_analysis_id' => $this->websiteAnalysisId,
            'exception' => $exception->getMessage(),
        ]);
        app(AnalysisPipeline::class)->dispatchBrandWheelAnalysisAfterCrawl($this->analysisId, $this->websiteAnalysisId);
    }
}
