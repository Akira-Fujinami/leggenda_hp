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
    ) {}

    abstract public function jobType(): JobType;

    /**
     * 実際の処理。想定内の失敗はAnalysisExceptionを投げること。
     * 成功/失敗によらず後続ジョブの起動が必要な場合(例: FetchStaticPageJob →
     * AnalyzeHtmlSeoJob)は、process()内で自前のtry/finallyを使わず、
     * onWebsiteJobTerminal()をオーバーライドして行うこと(下記docblock参照)。
     */
    abstract protected function process(AnalysisJobRecord $record, WebsiteAnalysis $websiteAnalysis, AnalysisPipeline $pipeline): void;

    /**
     * このジョブ自身のAnalysisJob行が実際に終端状態(Completed/Failed)へ
     * 確定した瞬間にのみ、通る経路によらず正確に1回呼び出されるフック。
     * 後続ジョブのdispatch(カスケード起動)はここで行うこと。
     *
     * process()自身のtry/finallyでカスケードdispatchを行ってはいけない
     * ―― process()のfinallyは、リトライのためrelease()される試行でも
     * 無条件に実行されてしまう。後続ジョブは(ShouldBeUnique+
     * AnalysisPipeline::markRunning()の終端状態チェックにより)一度
     * Completedになると二度と実行されないため、「まだ結果が確定していない
     * 試行」で後続を起動してしまうと、後続がno-op相当の結果を記録して
     * 終端化し、その後の再試行が実際に成功しても結果が反映されない
     * (サイレントなデータロス)まま放置される。このフックはhandle()の
     * 3箇所(成功時markCompleted後、AnalysisException非リトライ時の
     * markFailed後、汎用\Throwableのmarkfailed後)と、failed()の
     * markFailed後の計4箇所――いずれも「本当にterminalへ確定した後」
     * ――からのみ呼ばれ、release()で戻る2箇所からは呼ばれない。
     */
    protected function onWebsiteJobTerminal(AnalysisPipeline $pipeline): void
    {
        // 既定はno-op。
    }

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
            $this->onWebsiteJobTerminal($pipeline);
        } catch (AnalysisException $e) {
            if ($e->errorCode->isRetryable() && $this->attempts() < $this->tries && $this->canRelease()) {
                $this->release($this->nextBackoffSeconds());

                return;
            }

            $pipeline->markFailed($record, $e->errorCode, $e->getMessage());
            $this->onWebsiteJobTerminal($pipeline);
        } catch (\Throwable $e) {
            report($e);

            if ($this->attempts() < $this->tries && $this->canRelease()) {
                $this->release($this->nextBackoffSeconds());

                return;
            }

            $pipeline->markFailed($record, AnalysisErrorCode::UnknownError, '予期しないエラーが発生しました。');
            $this->onWebsiteJobTerminal($pipeline);
        } finally {
            $pipeline->updateWebsiteAnalysisProgress($this->websiteAnalysisId);
            $pipeline->maybeFinalizeWebsiteAnalysis($this->websiteAnalysisId);
            $pipeline->updateAnalysisProgress($this->analysisId);
        }
    }

    /**
     * Laravelのキュー基盤自身がジョブを終了させた場合(例: $timeoutを超過した、
     * または$triesを使い切った後の再スケジュール失敗)に呼ばれる。この経路は
     * handle()内のtry/catchを経由せず直接プロセスが終了させられるため、
     * ここでmarkFailed()しておかないとAnalysisJob.statusが「running」のまま
     * 永久に残り、maybeFinalizeWebsiteAnalysis()の「全Job終端待ち」が
     * 完了せず、WebsiteAnalysis/Analysisの確定(partial/failed判定含む)が
     * 永久に止まってしまう。
     */
    public function failed(\Throwable $exception): void
    {
        report($exception);

        $pipeline = app(AnalysisPipeline::class);

        $record = AnalysisJobRecord::query()
            ->where('analysis_id', $this->analysisId)
            ->where('website_analysis_id', $this->websiteAnalysisId)
            ->where('job_type', $this->jobType())
            ->first();

        if ($record === null || $record->status->isTerminal()) {
            return;
        }

        $pipeline->markFailed($record, AnalysisErrorCode::UnknownError, 'ジョブがタイムアウトしたか、想定外のエラーで終了しました。');
        $this->onWebsiteJobTerminal($pipeline);

        $pipeline->updateWebsiteAnalysisProgress($this->websiteAnalysisId);
        $pipeline->maybeFinalizeWebsiteAnalysis($this->websiteAnalysisId);
        $pipeline->updateAnalysisProgress($this->analysisId);
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
