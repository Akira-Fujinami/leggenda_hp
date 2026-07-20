<?php

namespace App\Jobs;

use App\Models\AiAnalysisResult as AiAnalysisResultRecord;
use App\Models\WebsiteAnalysis;
use App\Services\AiAnalysis\AiAnalysisException;
use App\Services\AiAnalysis\AiAnalysisInputFactory;
use App\Services\AiAnalysis\AiAnalysisProviderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * WebsiteAnalysis単位でAI分析を生成する。AiAnalysisProviderにのみ依存し、
 * Semrush同様Provider固有の知識は一切持たない。
 *
 * 設計方針:
 * - 事前にControllerがai_analysis_resultsへstatus=pendingの行を作成し、
 *   そのIDだけを受け取る(コスト発生・重複実行防止の判断はController側)。
 * - 同一website_analysis_id×同一input_hashで既に成功している結果があれば、
 *   APIを再度呼ばずそれを複製する(冪等・コスト削減)。
 * - AI Provider未設定/認証エラー等は永久に待ち続けず、明確なエラーとして
 *   記録して正常終了する(このJob自体がfailedになるのは想定外の例外のみ)。
 * - レート制限のみリトライ対象とし、認証エラーはリトライしない。
 */
class GenerateAiAnalysisJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 90;

    /** @var int|array<int, int> */
    public $backoff = [30];

    public $uniqueFor = 300;

    public function __construct(
        public readonly int $aiAnalysisResultId,
    ) {
    }

    public function uniqueId(): string
    {
        return "ai-analysis:{$this->aiAnalysisResultId}";
    }

    public function handle(AiAnalysisInputFactory $inputFactory): void
    {
        $record = AiAnalysisResultRecord::find($this->aiAnalysisResultId);

        if ($record === null) {
            return;
        }

        $websiteAnalysis = WebsiteAnalysis::find($record->website_analysis_id);

        if ($websiteAnalysis === null) {
            $record->update(['status' => 'error', 'error_code' => 'WEBSITE_ANALYSIS_NOT_FOUND', 'error_message' => '対象のWebsiteAnalysisが見つかりません。']);

            return;
        }

        $record->update(['status' => 'running']);

        try {
            $input = $inputFactory->build($websiteAnalysis);
        } catch (\Throwable $e) {
            $record->update(['status' => 'error', 'error_code' => 'AI_INPUT_BUILD_FAILED', 'error_message' => $e->getMessage()]);

            return;
        }

        $inputHash = hash('sha256', json_encode($input->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $reusable = AiAnalysisResultRecord::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('input_hash', $inputHash)
            ->where('status', 'success')
            ->where('id', '!=', $record->id)
            ->latest('generated_at')
            ->first();

        if ($reusable !== null) {
            $record->update([
                'provider' => $reusable->provider,
                'model' => $reusable->model,
                'status' => 'success',
                'summary' => $reusable->summary,
                'strengths' => $reusable->strengths,
                'weaknesses' => $reusable->weaknesses,
                'priority_actions' => $reusable->priority_actions,
                'competitor_insights' => $reusable->competitor_insights,
                'cautions' => $reusable->cautions,
                'confidence' => $reusable->confidence,
                'is_mock' => $reusable->is_mock,
                'input_hash' => $inputHash,
                'usage_input_tokens' => 0,
                'usage_output_tokens' => 0,
                'duration_ms' => 0,
                'error_code' => null,
                'error_message' => null,
                'generated_at' => now(),
            ]);

            return;
        }

        try {
            $provider = app(AiAnalysisProviderFactory::class)->make();
        } catch (AiAnalysisException $e) {
            $record->update(['status' => 'error', 'error_code' => $e->errorCode, 'error_message' => $e->getMessage(), 'input_hash' => $inputHash]);

            return;
        }

        $started = microtime(true);

        try {
            $outcome = $provider->analyze($input);
        } catch (AiAnalysisException $e) {
            if ($e->isRetryable && $this->attempts() < $this->tries && $this->job !== null) {
                $record->update(['status' => 'pending']);
                $this->release($e->retryAfterSeconds ?? $this->backoff[0]);

                return;
            }

            $record->update(['status' => 'error', 'error_code' => $e->errorCode, 'error_message' => $e->getMessage(), 'input_hash' => $inputHash]);

            return;
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $result = $outcome->result;

        $record->update([
            'provider' => $result->provider,
            'model' => $result->model,
            'status' => 'success',
            'summary' => $result->summary,
            'strengths' => array_map(fn ($item) => $item->toArray(), $result->strengths),
            'weaknesses' => array_map(fn ($item) => $item->toArray(), $result->weaknesses),
            'priority_actions' => array_map(fn ($item) => $item->toArray(), $result->priorityActions),
            'competitor_insights' => array_map(fn ($item) => $item->toArray(), $result->competitorInsights),
            'cautions' => $result->cautions,
            'confidence' => $result->confidence,
            'is_mock' => $result->isMock,
            'input_hash' => $inputHash,
            'usage_input_tokens' => $outcome->usageInputTokens,
            'usage_output_tokens' => $outcome->usageOutputTokens,
            'duration_ms' => $durationMs,
            'error_code' => null,
            'error_message' => null,
            'generated_at' => now(),
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        $record = AiAnalysisResultRecord::find($this->aiAnalysisResultId);
        $record?->update([
            'status' => 'error',
            'error_code' => 'AI_JOB_FAILED',
            'error_message' => $exception?->getMessage(),
        ]);
    }
}
