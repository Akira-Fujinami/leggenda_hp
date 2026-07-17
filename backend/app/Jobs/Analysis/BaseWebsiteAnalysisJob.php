<?php

namespace App\Jobs\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Enums\JobType;
use App\Exceptions\Analysis\AnalysisException;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * サイト単位で実行されるAnalysisJob(Fetch/Render/Screenshot/Lighthouse/SEO/技術検出)の
 * 共通の実行フロー。
 *
 * 設計方針: process()内で発生した「想定内の失敗」はAnalysisExceptionとして
 * 投げてもらい、ここでcatchしてAnalysisJob.statusへ反映する。こうすることで
 * 失敗がPHP例外としてキューまで伝播せず(=queue:workのリトライ機構に
 * 依存しない)、Bus::chainのような強い連鎖を組まなくてもパイプライン全体が
 * 止まらずに進む。ジョブ間の依存はfinally節での完了判定カスケード
 * (AnalysisPipeline::maybeFinalizeWebsiteAnalysis)で表現する。
 */
abstract class BaseWebsiteAnalysisJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $uniqueFor = 3600;

    public $tries = 3;

    public $timeout = 30;

    /** @var int|array<int, int> */
    public $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $analysisId,
        public readonly int $websiteAnalysisId,
    ) {
    }

    abstract public function jobType(): JobType;

    /**
     * 実際の処理。想定内の失敗はAnalysisExceptionを投げること。
     * 成功/失敗によらず後続ジョブの起動が必要な場合は、process()内で
     * 自前のtry/finallyを使い$pipelineを使って行うこと
     * (例: FetchStaticPageJob → AnalyzeHtmlSeoJob)。
     */
    abstract protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void;

    public function uniqueId(): string
    {
        return "analysis-job:{$this->analysisId}:{$this->websiteAnalysisId}:{$this->jobType()->value}";
    }

    public function handle(AnalysisPipeline $pipeline): void
    {
        $websiteAnalysis = WebsiteAnalysis::find($this->websiteAnalysisId);

        if ($websiteAnalysis === null) {
            return;
        }

        $record = $pipeline->markRunning($this->analysisId, $this->websiteAnalysisId, $this->jobType());

        if ($record === null) {
            return;
        }

        try {
            $this->process($record, $websiteAnalysis, $pipeline);
            $pipeline->markCompleted($record);
        } catch (AnalysisException $e) {
            if ($e->errorCode->isRetryable() && $this->attempts() < $this->tries && $this->canRelease()) {
                $this->release($this->nextBackoffSeconds());

                return;
            }

            $pipeline->markFailed($record, $e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            if ($this->attempts() < $this->tries && $this->canRelease()) {
                $this->release($this->nextBackoffSeconds());

                return;
            }

            $pipeline->markFailed($record, AnalysisErrorCode::UnknownError, '予期しないエラーが発生しました。');
        } finally {
            $pipeline->updateWebsiteAnalysisProgress($this->websiteAnalysisId);
            $pipeline->maybeFinalizeWebsiteAnalysis($this->websiteAnalysisId);
            $pipeline->updateAnalysisProgress($this->analysisId);
        }
    }

    private function canRelease(): bool
    {
        return $this->job !== null;
    }

    private function nextBackoffSeconds(): int
    {
        $backoff = is_array($this->backoff) ? $this->backoff : [$this->backoff];
        $index = min(max($this->attempts() - 1, 0), count($backoff) - 1);

        return $backoff[$index] ?? 10;
    }
}
