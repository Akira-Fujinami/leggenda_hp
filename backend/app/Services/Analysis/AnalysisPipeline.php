<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisErrorCode;
use App\Enums\AnalysisJobStatus;
use App\Enums\Device;
use App\Enums\JobType;
use App\Jobs\Analysis\AnalyzeHtmlSeoJob;
use App\Jobs\Analysis\CaptureScreenshotJob;
use App\Jobs\Analysis\DetectTechnologyJob;
use App\Jobs\Analysis\FetchExternalSeoDataJob;
use App\Jobs\Analysis\FetchRobotsJob;
use App\Jobs\Analysis\FetchSitemapJob;
use App\Jobs\Analysis\FetchStaticPageJob;
use App\Jobs\Analysis\FinalizeAnalysisJob;
use App\Jobs\Analysis\FinalizeWebsiteAnalysisJob;
use App\Jobs\Analysis\ReanalyzeRenderedHtmlJob;
use App\Jobs\Analysis\RenderPageJob;
use App\Jobs\Analysis\RunLighthouseJob;
use App\Models\Analysis;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\WebsiteAnalysis;
use Illuminate\Support\Collection;

/**
 * 分析ジョブパイプラインの共通処理(AnalysisJob行の登録・状態遷移・
 * 完了判定によるカスケード起動)を集約する。個々のJobクラスはこれを介して
 * 状態を更新するため、「完了扱いにする条件」や「次に何を起動するか」の
 * ロジックが1箇所にまとまる。
 */
class AnalysisPipeline
{
    /**
     * サイト単位の10ジョブ分のAnalysisJob行を「pending」で事前登録する。
     * StartAnalysisJobがサイトへのファンアウトを行う前に呼び出すことで、
     * まだ実行されていないジョブ種別もmaybeFinalizeWebsiteAnalysis()の
     * 「全ジョブ終端待ち」判定に正しく含められるようにする。
     */
    public function registerWebsiteJobPlaceholders(WebsiteAnalysis $websiteAnalysis): void
    {
        foreach (JobType::websiteLevelTypes() as $jobType) {
            AnalysisJobRecord::query()->firstOrCreate(
                [
                    'analysis_id' => $websiteAnalysis->analysis_id,
                    'website_analysis_id' => $websiteAnalysis->id,
                    'job_type' => $jobType,
                ],
                [
                    'queue_name' => $jobType->queueName(),
                    'status' => AnalysisJobStatus::Pending,
                    'progress' => 0,
                    'attempts' => 0,
                ],
            );
        }
    }

    /**
     * サイト単位で最初に起動するジョブ群。AnalyzeHtmlSeoJobだけは
     * FetchStaticPageJobが取得したHTMLに依存するため、FetchStaticPageJob側の
     * 完了時に個別に起動する(ここでは起動しない)。
     */
    public function dispatchWebsiteFanOut(WebsiteAnalysis $websiteAnalysis): void
    {
        $analysisId = $websiteAnalysis->analysis_id;
        $websiteAnalysisId = $websiteAnalysis->id;

        FetchStaticPageJob::dispatch($analysisId, $websiteAnalysisId)->onQueue(JobType::FetchStaticPage->queueName());
        FetchRobotsJob::dispatch($analysisId, $websiteAnalysisId)->onQueue(JobType::FetchRobots->queueName());
        FetchSitemapJob::dispatch($analysisId, $websiteAnalysisId)->onQueue(JobType::FetchSitemap->queueName());
        RenderPageJob::dispatch($analysisId, $websiteAnalysisId)->onQueue(JobType::RenderPage->queueName());
        CaptureScreenshotJob::dispatch($analysisId, $websiteAnalysisId, Device::Desktop)->onQueue(JobType::CaptureScreenshotDesktop->queueName());
        CaptureScreenshotJob::dispatch($analysisId, $websiteAnalysisId, Device::Mobile)->onQueue(JobType::CaptureScreenshotMobile->queueName());
        RunLighthouseJob::dispatch($analysisId, $websiteAnalysisId)->onQueue(JobType::RunLighthouse->queueName());
        DetectTechnologyJob::dispatch($analysisId, $websiteAnalysisId)->onQueue(JobType::DetectTechnology->queueName());
        FetchExternalSeoDataJob::dispatch($analysisId, $websiteAnalysisId)->onQueue(JobType::FetchExternalSeoData->queueName());
    }

    public function dispatchHtmlSeoAnalysis(int $analysisId, int $websiteAnalysisId): void
    {
        AnalyzeHtmlSeoJob::dispatch($analysisId, $websiteAnalysisId)->onQueue(JobType::AnalyzeHtmlSeo->queueName());
    }

    /**
     * RenderPageJobの終端(成功・失敗いずれも)から必ず呼び出す。
     * ReanalyzeRenderedHtmlJob側で「レンダリング済みHTMLが利用可能か」を
     * 判定するため、ここでは無条件にdispatchしてよい。
     */
    public function dispatchReanalysis(int $analysisId, int $websiteAnalysisId): void
    {
        ReanalyzeRenderedHtmlJob::dispatch($analysisId, $websiteAnalysisId)->onQueue(JobType::ReanalyzeRenderedHtml->queueName());
    }

    /**
     * (analysis_id, website_analysis_id, job_type)のAnalysisJob行を
     * 冪等に用意し、まだ終端状態でなければ実行中としてマークする。
     * 既に終端状態(completed/failed)の場合はnullを返し、呼び出し元は
     * 処理をスキップする(重複実行防止)。
     */
    public function markRunning(int $analysisId, ?int $websiteAnalysisId, JobType $jobType): ?AnalysisJobRecord
    {
        $record = AnalysisJobRecord::query()->firstOrCreate(
            [
                'analysis_id' => $analysisId,
                'website_analysis_id' => $websiteAnalysisId,
                'job_type' => $jobType,
            ],
            [
                'queue_name' => $jobType->queueName(),
                'status' => AnalysisJobStatus::Pending,
                'progress' => 0,
                'attempts' => 0,
            ],
        );

        if ($record->status->isTerminal()) {
            return null;
        }

        $record->update([
            'status' => AnalysisJobStatus::Running,
            'attempts' => $record->attempts + 1,
            'started_at' => $record->started_at ?? now(),
        ]);

        return $record;
    }

    public function markCompleted(AnalysisJobRecord $record): void
    {
        $record->update([
            'status' => AnalysisJobStatus::Completed,
            'progress' => 100,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(AnalysisJobRecord $record, AnalysisErrorCode $errorCode, string $message): void
    {
        $record->update([
            'status' => AnalysisJobStatus::Failed,
            'failed_at' => now(),
            'error_code' => $errorCode->value,
            'error_message' => $message,
        ]);
    }

    /**
     * サイト単位のジョブが1件完了するたびに、進捗(0-100)を再計算して
     * WebsiteAnalysisへ反映する。進捗ポーリングAPIがリアルタイムに近い値を
     * 返せるよう、Finalize待ちを待たずに都度更新する。
     */
    public function updateWebsiteAnalysisProgress(int $websiteAnalysisId): void
    {
        $websiteAnalysis = WebsiteAnalysis::find($websiteAnalysisId);

        if ($websiteAnalysis === null || $websiteAnalysis->status->isTerminal()) {
            return;
        }

        $jobs = AnalysisJobRecord::query()
            ->where('website_analysis_id', $websiteAnalysisId)
            ->whereIn('job_type', JobType::websiteLevelTypes())
            ->get();

        $progress = app(ProgressCalculator::class)->forWebsiteAnalysis($jobs);

        $websiteAnalysis->update(['progress' => $progress]);
    }

    /**
     * Analysis配下の各WebsiteAnalysisの進捗平均を、Analysis.progressへ反映する。
     */
    public function updateAnalysisProgress(int $analysisId): void
    {
        $analysis = Analysis::find($analysisId);

        if ($analysis === null || $analysis->status->isTerminal()) {
            return;
        }

        $websiteAnalyses = WebsiteAnalysis::query()->where('analysis_id', $analysisId)->get();
        $progress = app(ProgressCalculator::class)->forAnalysis($websiteAnalyses);

        $analysis->update(['progress' => $progress]);
    }

    /**
     * サイト単位の10ジョブが全て終端状態になったら、WebsiteAnalysisの
     * 集計(スコア・ステータス)を行うFinalizeWebsiteAnalysisJobを起動する。
     * FinalizeWebsiteAnalysisJob自体もShouldBeUniqueなので、複数の
     * 兄弟ジョブがほぼ同時に完了しても二重起動はキューイング層で防がれる。
     */
    public function maybeFinalizeWebsiteAnalysis(int $websiteAnalysisId): void
    {
        $websiteAnalysis = WebsiteAnalysis::find($websiteAnalysisId);

        if ($websiteAnalysis === null || $websiteAnalysis->status->isTerminal()) {
            return;
        }

        $requiredTypes = JobType::websiteFanOutTypes();

        $jobs = AnalysisJobRecord::query()
            ->where('website_analysis_id', $websiteAnalysisId)
            ->whereIn('job_type', $requiredTypes)
            ->get();

        if ($jobs->count() < count($requiredTypes)) {
            return;
        }

        if (! $jobs->every(fn (AnalysisJobRecord $job) => $job->status->isTerminal())) {
            return;
        }

        FinalizeWebsiteAnalysisJob::dispatch($websiteAnalysis->analysis_id, $websiteAnalysisId)
            ->onQueue(JobType::FinalizeWebsiteAnalysis->queueName());
    }

    /**
     * Analysis配下の全WebsiteAnalysisが終端状態になったら、Analysis全体の
     * 集計を行うFinalizeAnalysisJobを起動する。
     */
    public function maybeFinalizeAnalysis(int $analysisId): void
    {
        $analysis = Analysis::find($analysisId);

        if ($analysis === null || $analysis->status->isTerminal()) {
            return;
        }

        /** @var Collection<int, WebsiteAnalysis> $websiteAnalyses */
        $websiteAnalyses = WebsiteAnalysis::query()->where('analysis_id', $analysisId)->get();

        if ($websiteAnalyses->isEmpty()) {
            return;
        }

        if (! $websiteAnalyses->every(fn (WebsiteAnalysis $wa) => $wa->status->isTerminal())) {
            return;
        }

        FinalizeAnalysisJob::dispatch($analysisId)->onQueue(JobType::FinalizeAnalysis->queueName());
    }
}
