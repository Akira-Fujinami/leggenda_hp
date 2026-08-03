<?php

namespace App\Jobs;

use App\Enums\AnalysisErrorCode;
use App\Enums\JobType;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\BrandWheelAnalysisResult as BrandWheelAnalysisResultRecord;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\BrandWheel\BrandWheelAnalysisException;
use App\Services\BrandWheel\BrandWheelAnalysisInputFactory;
use App\Services\BrandWheel\BrandWheelAnalysisProvider;
use App\Services\BrandWheel\BrandWheelAnalysisProviderFactory;
use App\Services\BrandWheel\BrandWheelCompletionNotifier;
use App\Services\BrandWheel\Data\BrandWheelAnalysisInput;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * WebsiteAnalysis単位でブランド・ホイール(6軸)分析を生成する。
 * GenerateAiAnalysisJobと同じ設計方針:
 * - 事前にAnalysisPipeline::dispatchWebsiteFanOut()がbrand_wheel_analysis_
 *   resultsへstatus=pendingの行を作成し(自社・競合の両方、診断実行時)、
 *   そのIDだけを受け取る。
 * - 同一website_analysis_id×同一input_hashで既に成功している結果があれば、
 *   APIを再度呼ばずそれを複製する(冪等・コスト削減)。
 * - Provider未設定/認証エラー等は永久に待ち続けず、明確なエラーとして
 *   記録して正常終了する(このJob自体がfailedになるのは想定外の例外のみ)。
 *   これにより、このJobの成否が診断・1通目メールの完了フロー全体を
 *   ブロックすることはない。
 * - レート制限のみリトライ対象とし、認証エラーはリトライしない。
 *
 * タイムアウト不変条件(2026-07-24の本番障害の再発防止): $timeoutは
 * 必ずAI呼び出しのHTTPタイムアウト(services.brand_wheel_ai.timeout)+30秒以上に
 * なるよう、固定値ではなくconstructorで動的に計算する ―― 運用でタイムアウト値を
 * 変更しても、この不変条件が自動的に保たれるようにするため。
 *
 * AnalysisJob連携(2026-08-03、JobType::GenerateBrandWheelAnalysisへ追加時):
 * このJobはAnalyzerを使わないためBaseWebsiteAnalysisJob(ANALYZER_CHAIN系の
 * try/catch/finallyテンプレート)を継承しない。既存の複雑な分岐
 * (forceRefreshバイパス・再利用キャッシュ命中・insufficient_input早期return・
 * リトライ対象エラーのrelease())をそのまま保ちつつ、各終端経路
 * (insufficient_input/再利用キャッシュ命中/成功/リトライ不可エラー)で
 * 個別にmarkRunning()/markCompleted()/markFailed()+進捗カスケードを呼ぶ。
 * リトライ対象エラーでrelease()する経路は、他のBaseWebsiteAnalysisJob系
 * ジョブと同じく進捗カスケードを呼ばない(まだ結果が確定していないため)。
 */
class GenerateBrandWheelAnalysisJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout;

    /** @var int|array<int, int> */
    public $backoff = [30];

    public $uniqueFor;

    public function __construct(
        public readonly int $brandWheelAnalysisResultId,
        // #99の安定性確認(同一サイトを複数回評価し、AIの出力自体が揺れるかを
        // 見る)専用のバイパス。既定はfalseで、既存の呼び出し元(診断実行時の
        // fan-out)は一切このパラメータを渡さないため、本番の挙動は
        // 変わらない。production環境ではhandle()内で強制的に無効化する
        // (2026-07-30の指摘 ―― このバイパス自体も本番では使えないこと)。
        public readonly bool $forceRefresh = false,
    ) {
        $this->timeout = ((int) config('services.brand_wheel_ai.timeout', 60)) + 30;
        // ShouldBeUniqueのロック期間はJobのタイムアウトより確実に長く保つ
        // (ロックが先に切れると、二重押下ではなく単発の遅延実行だけで
        // 重複起動してしまうため)。
        $this->uniqueFor = $this->timeout * 3;
    }

    public function uniqueId(): string
    {
        return "brand-wheel-analysis:{$this->brandWheelAnalysisResultId}";
    }

    public function handle(BrandWheelAnalysisInputFactory $inputFactory, AnalysisPipeline $pipeline): void
    {
        $record = BrandWheelAnalysisResultRecord::find($this->brandWheelAnalysisResultId);

        if ($record === null) {
            return;
        }

        $analysisId = $record->analysis_id;
        $websiteAnalysisId = $record->website_analysis_id;

        $jobRecord = $pipeline->markRunning($analysisId, $websiteAnalysisId, JobType::GenerateBrandWheelAnalysis);

        if ($jobRecord === null) {
            // 既に終端状態(重複実行・キュー再処理等)。二重に処理しない。
            return;
        }

        $websiteAnalysis = $websiteAnalysisId !== null ? WebsiteAnalysis::find($websiteAnalysisId) : null;

        if ($websiteAnalysis === null) {
            $record->update(['status' => 'error', 'error_code' => 'WEBSITE_ANALYSIS_NOT_FOUND', 'error_message' => '対象のWebsiteAnalysisが見つかりません。']);
            $this->completeAsFailed($pipeline, $jobRecord, $analysisId, $websiteAnalysisId, '対象のWebsiteAnalysisが見つかりません。');

            return;
        }

        $record->update(['status' => 'running']);

        try {
            $input = $inputFactory->build($websiteAnalysis);
        } catch (\Throwable $e) {
            $record->update(['status' => 'error', 'error_code' => 'BRAND_WHEEL_INPUT_BUILD_FAILED', 'error_message' => $e->getMessage()]);
            $this->completeAsFailed($pipeline, $jobRecord, $analysisId, $websiteAnalysisId, $e->getMessage());

            return;
        }

        if ($this->isInputInsufficient($input)) {
            // 「サイトに記述が読み取れなかった」(=評価した結果、何も無かった)
            // と「サイトの記述を読みに行けなかった」(=生HTML取得・ストレージ
            // 到達に失敗した等)を混同しないため、AIを一切呼ばず、6軸すべて
            // unreadという体裁の整った結果ではなく、判定自体を持たない
            // insufficient_inputとして記録する(2026-07-29の指摘)。
            $record->update([
                'status' => 'insufficient_input',
                'provider' => null,
                'model' => null,
                'prompt_version' => null,
                'axes' => null,
                'core_value_readable' => null,
                'core_value_evidence' => null,
                'key_message' => null,
                'impression' => null,
                'quality_dimension_notes' => null,
                'cautions' => null,
                'axis_state_counts' => null,
                'is_mock' => false,
                'input_hash' => hash('sha256', json_encode($input->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'input_truncated' => $input->inputTruncated,
                'source_pages' => $input->sourcePages,
                'usage_input_tokens' => null,
                'usage_output_tokens' => null,
                'duration_ms' => 0,
                'error_code' => null,
                'error_message' => null,
                'generated_at' => now(),
            ]);

            Log::info('Brand wheel analysis skipped: insufficient input', [
                'brand_wheel_analysis_result_id' => $record->id,
                'website_analysis_id' => $websiteAnalysis->id,
                'recruit_body_chars' => mb_strlen($input->recruitPageBodyText),
                'homepage_body_chars' => mb_strlen($input->homepageBodyText),
            ]);

            app(BrandWheelCompletionNotifier::class)->notifyIfReady($record);
            $this->completeAsSuccess($pipeline, $jobRecord, $analysisId, $websiteAnalysisId);

            return;
        }

        try {
            $provider = app(BrandWheelAnalysisProviderFactory::class)->make();
        } catch (BrandWheelAnalysisException $e) {
            $record->update(['status' => 'error', 'error_code' => $e->errorCode, 'error_message' => $e->getMessage()]);
            $this->completeAsFailed($pipeline, $jobRecord, $analysisId, $websiteAnalysisId, $e->getMessage());

            return;
        }

        $inputHash = $this->computeInputHash($input, $provider);

        // production環境ではforceRefreshを常に無視する(境界そのもので強制する
        // ―― production環境でこのJobにforceRefresh=trueが渡ること自体
        // 想定していないが、二重に安全側へ倒す)。
        $skipReuseCheck = $this->forceRefresh && ! app()->environment('production');

        $reusable = $skipReuseCheck ? null : BrandWheelAnalysisResultRecord::query()
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
                'prompt_version' => $reusable->prompt_version,
                'axes' => $reusable->axes,
                'core_value_readable' => $reusable->core_value_readable,
                'core_value_evidence' => $reusable->core_value_evidence,
                'key_message' => $reusable->key_message,
                'impression' => $reusable->impression,
                'quality_dimension_notes' => $reusable->quality_dimension_notes,
                'cautions' => $reusable->cautions,
                'axis_state_counts' => $reusable->axis_state_counts,
                'is_mock' => $reusable->is_mock,
                'input_hash' => $inputHash,
                'input_truncated' => $input->inputTruncated,
                'source_pages' => $input->sourcePages,
                'usage_input_tokens' => 0,
                'usage_output_tokens' => 0,
                'duration_ms' => 0,
                'error_code' => null,
                'error_message' => null,
                'generated_at' => now(),
            ]);

            app(BrandWheelCompletionNotifier::class)->notifyIfReady($record);
            $this->completeAsSuccess($pipeline, $jobRecord, $analysisId, $websiteAnalysisId);

            return;
        }

        $started = microtime(true);

        try {
            $outcome = $provider->analyze($input);
        } catch (BrandWheelAnalysisException $e) {
            if ($e->isRetryable && $this->attempts() < $this->tries && $this->job !== null) {
                $record->update(['status' => 'pending']);
                $this->release($e->retryAfterSeconds ?? $this->backoff[0]);

                // リトライ対象: まだ結果が確定していないため、markCompleted/
                // markFailed・進捗カスケードのいずれも呼ばない
                // (BaseWebsiteAnalysisJobのrelease()経路と同じ扱い)。
                return;
            }

            $record->update(['status' => 'error', 'error_code' => $e->errorCode, 'error_message' => $e->getMessage(), 'input_hash' => $inputHash, 'input_truncated' => $input->inputTruncated]);
            $this->completeAsFailed($pipeline, $jobRecord, $analysisId, $websiteAnalysisId, $e->getMessage());

            return;
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $result = $outcome->result;

        $record->update([
            'provider' => $result->provider,
            'model' => $result->model,
            'status' => 'success',
            'prompt_version' => $result->promptVersion,
            'axes' => array_map(fn ($axis) => $axis->toArray(), $result->axes),
            'core_value_readable' => $result->coreValue->readable,
            'core_value_evidence' => $result->coreValue->evidence,
            'key_message' => $result->keyMessage,
            'impression' => $result->impression,
            'quality_dimension_notes' => $result->qualityDimensionNotes,
            'cautions' => $result->cautions,
            'axis_state_counts' => $result->axisStateCounts,
            'is_mock' => $result->isMock,
            'input_hash' => $inputHash,
            'input_truncated' => $input->inputTruncated,
            'source_pages' => $input->sourcePages,
            'usage_input_tokens' => $outcome->usageInputTokens,
            'usage_output_tokens' => $outcome->usageOutputTokens,
            'duration_ms' => $durationMs,
            'error_code' => null,
            'error_message' => null,
            'generated_at' => now(),
        ]);

        // コスト監視用(#99の実測作業)。本文テキスト・evidence等サイトの
        // 実内容は一切含めず、件数・識別子のみを記録する。
        Log::info('Brand wheel analysis completed', [
            'brand_wheel_analysis_result_id' => $record->id,
            'website_analysis_id' => $websiteAnalysis->id,
            'provider' => $result->provider,
            'model' => $result->model,
            'usage_input_tokens' => $outcome->usageInputTokens,
            'usage_output_tokens' => $outcome->usageOutputTokens,
            'duration_ms' => $durationMs,
            'input_truncated' => $input->inputTruncated,
        ]);

        app(BrandWheelCompletionNotifier::class)->notifyIfReady($record);
        $this->completeAsSuccess($pipeline, $jobRecord, $analysisId, $websiteAnalysisId);
    }

    /**
     * insufficient_input/再利用キャッシュ命中/成功のいずれも、BrandWheelAnalysisResult
     * としては正常な終端(判定結果が確定した)であり、AnalysisJobとしても
     * Completed扱いにする(ProgressCalculatorの既存方針: 「失敗したJobも
     * 完了扱いとして進捗には加算する」の逆で、正常に終わったものは当然
     * completedにする)。
     */
    private function completeAsSuccess(AnalysisPipeline $pipeline, AnalysisJobRecord $jobRecord, int $analysisId, ?int $websiteAnalysisId): void
    {
        $pipeline->markCompleted($jobRecord);
        $this->cascadeProgress($pipeline, $analysisId, $websiteAnalysisId);
    }

    private function completeAsFailed(AnalysisPipeline $pipeline, AnalysisJobRecord $jobRecord, int $analysisId, ?int $websiteAnalysisId, string $message): void
    {
        $pipeline->markFailed($jobRecord, AnalysisErrorCode::UnknownError, $message);
        $this->cascadeProgress($pipeline, $analysisId, $websiteAnalysisId);
    }

    private function cascadeProgress(AnalysisPipeline $pipeline, int $analysisId, ?int $websiteAnalysisId): void
    {
        if ($websiteAnalysisId !== null) {
            $pipeline->updateWebsiteAnalysisProgress($websiteAnalysisId);
            $pipeline->maybeFinalizeWebsiteAnalysis($websiteAnalysisId);
        }
        $pipeline->updateAnalysisProgress($analysisId);
    }

    /**
     * 採用ページ本文・トップページ本文の合計文字数が
     * config('brand_wheel.insufficient_input_min_total_chars')未満かどうか。
     * 見出し・ナビゲーションラベルは判定に含めない(具体的な裏づけ根拠は
     * 本文からしか得られないため)。
     */
    private function isInputInsufficient(BrandWheelAnalysisInput $input): bool
    {
        $totalChars = mb_strlen($input->recruitPageBodyText) + mb_strlen($input->homepageBodyText);
        $minChars = (int) config('brand_wheel.insufficient_input_min_total_chars', 200);

        return $totalChars < $minChars;
    }

    /**
     * 入力テキスト・PROMPT_VERSION・config('brand_wheel')のフィンガープリント・
     * モデル名の4つから再利用キーを算出する(2026-07-29の指摘)。いずれか1つでも
     * 変われば別のハッシュになり、古い基準で生成された結果は再利用されない ――
     * config/brand_wheel.phpの軸定義・教師データ・閾値を変更した場合や、
     * PROMPT_VERSIONを上げた場合、モデルを切り替えた場合のいずれも対象。
     */
    private function computeInputHash(BrandWheelAnalysisInput $input, BrandWheelAnalysisProvider $provider): string
    {
        $configFingerprint = hash('sha256', json_encode(config('brand_wheel'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return hash('sha256', json_encode([
            'input' => $input->toArray(),
            'prompt_version' => $provider->promptVersion(),
            'config_fingerprint' => $configFingerprint,
            'model' => $provider->model(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function failed(?\Throwable $exception): void
    {
        $record = BrandWheelAnalysisResultRecord::find($this->brandWheelAnalysisResultId);

        if ($record === null) {
            return;
        }

        $record->update([
            'status' => 'error',
            'error_code' => 'BRAND_WHEEL_JOB_FAILED',
            'error_message' => $exception?->getMessage(),
        ]);

        // Laravelのキュー基盤自身がJobを終了させた場合(timeout超過・tries使い
        // 切り後の再スケジュール失敗)、handle()内のtry/catchを経由せずここへ
        // 直接来る。ここでmarkFailed()しておかないとAnalysisJob.statusが
        // runningのまま残り、maybeFinalizeWebsiteAnalysis()の「全Job終端待ち」が
        // 完了しない(BaseWebsiteAnalysisJob::failed()と同じ理由、2026-07-25の
        // 本番障害の再発防止)。
        $pipeline = app(AnalysisPipeline::class);
        $jobRecord = AnalysisJobRecord::query()
            ->where('analysis_id', $record->analysis_id)
            ->where('website_analysis_id', $record->website_analysis_id)
            ->where('job_type', JobType::GenerateBrandWheelAnalysis)
            ->first();

        if ($jobRecord === null || $jobRecord->status->isTerminal()) {
            return;
        }

        $pipeline->markFailed($jobRecord, AnalysisErrorCode::JobTimeout, $exception?->getMessage() ?? 'ジョブがタイムアウトしたか、想定外のエラーで終了しました。');
        $this->cascadeProgress($pipeline, $record->analysis_id, $record->website_analysis_id);
    }
}
