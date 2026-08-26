<?php

namespace App\Jobs;

use App\Enums\AnalysisErrorCode;
use App\Enums\JobType;
use App\Enums\PageType;
use App\Jobs\Analysis\Concerns\ClassifiesJobFailureExceptions;
use App\Jobs\Analysis\Concerns\LogsAiRetryAttempts;
use App\Models\Analysis;
use App\Models\AnalysisJob as AnalysisJobRecord;
use App\Models\AnalysisPage;
use App\Models\BrandWheelAnalysisResult as BrandWheelAnalysisResultRecord;
use App\Models\LeadSession;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\BrandWheel\BrandWheelAnalysisException;
use App\Services\BrandWheel\BrandWheelAnalysisInputFactory;
use App\Services\BrandWheel\BrandWheelAnalysisProvider;
use App\Services\BrandWheel\BrandWheelAnalysisProviderFactory;
use App\Services\BrandWheel\BrandWheelCompletionNotifier;
use App\Services\BrandWheel\BrandWheelImprovementSuggestionDispatcher;
use App\Services\BrandWheel\BrandWheelReportEligibility;
use App\Services\BrandWheel\Data\BrandWheelAnalysisInput;
use App\Services\Lead\LeadSessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
    use ClassifiesJobFailureExceptions, Dispatchable, InteractsWithQueue, LogsAiRetryAttempts, Queueable, SerializesModels;

    public $tries;

    public $timeout;

    /** @var list<int> */
    public $backoff;

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

        // 依頼U(2026-08-26): $tries/$backoffはconfig+env経由で調整できる
        // ようにする(再デプロイ無しで「もう少し粘らせたい/諦めさせたい」を
        // 変更できるようにするため、依頼者指定)。docker/scripts/
        // backend-entrypoint.render.shのQUEUE_WORKER_TRIES(--tries、
        // ワーカー側の既定値)より、ここで設定するジョブ自身の$triesが
        // 優先される(Illuminate\Queue\Worker::markJobAsFailedIfAlreadyExceeds
        // MaxAttempts()が`$job->maxTries() ?? $maxTries`という順で解決する
        // ため。$job->maxTries()はこのジョブの$tries、$maxTriesはワーカーの
        // --triesであり、$job->maxTries()がnullでない限りそちらが勝つ)。
        $this->tries = (int) config('services.brand_wheel_ai.job_tries', 4);
        $this->backoff = (array) config('services.brand_wheel_ai.job_backoff_seconds', [30, 90, 180]);
    }

    /**
     * 依頼U-2(2026-08-26): 最初の試行から一定時間を過ぎたら、$triesに
     * 余裕があってもそれ以上再試行せずerrorで確定させる。config('lead.
     * stale_analysis_after_minutes')(30分、Analysis.statusがRunningのまま
     * 滞留する上限)より明確に短くしてある ―― これが無いと、レート制限が
     * 続いた場合に診断がstale判定の枠を長時間占有し続ける。
     *
     * retryUntil()と$triesの関係(Illuminate\Queue\Worker::
     * markJobAsFailedIfAlreadyExceedsMaxAttempts()参照): retryUntil()が
     * 非nullを返す場合、Laravelのワーカーはジョブを次に取り出す前に必ず
     * この期限を確認し、期限を過ぎていれば$triesの残り回数に関わらず
     * 即座に失敗させる(このジョブのhandle()すら呼ばれず、代わりに
     * failed()がMaxAttemptsExceededExceptionと共に呼ばれる ――
     * ClassifiesJobFailureExceptionsトレイトが既にこの例外型を
     * AnalysisErrorCode::MaxAttemptsExceededへ分類済み)。期限内であれば
     * $triesの回数制限がそのまま働く。すなわち「retryUntil()と$tries、
     * どちらか先に達したほうで打ち切られる」ことになる ―― ただし
     * retryUntil()が期限切れの場合はLaravel自身のこの仕組みが、$triesが
     * 先に尽きた場合はhandle()内の`$this->attempts() < $this->tries`の
     * 自前チェックが、それぞれ別の経路で打ち切る。
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('services.brand_wheel_ai.job_retry_until_minutes', 10));
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

        $this->logStorageDiagnostics($analysisId, $websiteAnalysisId);

        $record->update(['status' => 'running']);

        try {
            $input = $inputFactory->build($websiteAnalysis);
        } catch (\Throwable $e) {
            $this->finalizeBrandWheelResult($record, $websiteAnalysis, ['status' => 'error', 'error_code' => 'BRAND_WHEEL_INPUT_BUILD_FAILED', 'error_message' => $e->getMessage()]);
            $this->completeAsFailed($pipeline, $jobRecord, $analysisId, $websiteAnalysisId, $e->getMessage());

            return;
        }

        // 2026-08-19追加: analysis_id=45/website_analysis_id=93で観測された、
        // fetch_recruit_page/render_pageがいずれもcompletedなのに
        // source_pagesが両方ともunreadableになる不具合の原因切り分け用
        // (Job自体はcompleted扱いのため、この警告が唯一の監視シグナルになる)。
        if (in_array('unreadable', $input->sourcePages, true)) {
            Log::warning('Brand wheel analysis: a source page is unreadable despite the fetch/render jobs having completed', [
                'analysis_id' => $analysisId,
                'website_analysis_id' => $websiteAnalysisId,
                'hostname' => gethostname(),
                'source_pages' => $input->sourcePages,
            ]);
        }

        $inputInsufficient = $this->isInputInsufficient($input);

        if ($inputInsufficient) {
            // 「サイトに記述が読み取れなかった」(=評価した結果、何も無かった)
            // と「サイトの記述を読みに行けなかった」(=生HTML取得・ストレージ
            // 到達に失敗した等)を混同しないため、AIを一切呼ばず、6軸すべて
            // unreadという体裁の整った結果ではなく、判定自体を持たない
            // insufficient_inputとして記録する(2026-07-29の指摘)。
            //
            // 2026-08-24: insufficient_inputはリード向けレポートとしては
            // 状態メッセージのみで実質的な中身を持たない(「白紙」と同列)ため、
            // finalizeBrandWheelResult()経由でも診断回数は消費されない
            // (BrandWheelReportEligibility参照)。
            $this->finalizeBrandWheelResult($record, $websiteAnalysis, [
                'status' => 'insufficient_input',
                'provider' => null,
                'model' => null,
                'prompt_version' => null,
                'axes' => null,
                'core_value_readable' => null,
                'core_value_evidence' => null,
                'key_message' => null,
                'impression' => null,
                'positive_impression' => null,
                'negative_impression' => null,
                'quality_dimension_notes' => null,
                'cautions' => null,
                'axis_state_counts' => null,
                'is_mock' => false,
                'input_hash' => hash('sha256', json_encode($input->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'input_truncated' => $input->inputTruncated,
                'input_char_count' => $this->inputTotalChars($input),
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
            $this->finalizeBrandWheelResult($record, $websiteAnalysis, ['status' => 'error', 'error_code' => $e->errorCode, 'error_message' => $e->getMessage(), 'input_char_count' => $this->inputTotalChars($input)]);
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
            $this->finalizeBrandWheelResult($record, $websiteAnalysis, [
                'provider' => $reusable->provider,
                'model' => $reusable->model,
                'status' => 'success',
                'prompt_version' => $reusable->prompt_version,
                'axes' => $reusable->axes,
                'core_value_readable' => $reusable->core_value_readable,
                'core_value_evidence' => $reusable->core_value_evidence,
                'key_message' => $reusable->key_message,
                'impression' => $reusable->impression,
                'positive_impression' => $reusable->positive_impression,
                'negative_impression' => $reusable->negative_impression,
                'quality_dimension_notes' => $reusable->quality_dimension_notes,
                'cautions' => $reusable->cautions,
                'axis_state_counts' => $reusable->axis_state_counts,
                'is_mock' => $reusable->is_mock,
                'input_hash' => $inputHash,
                'input_truncated' => $input->inputTruncated,
                'input_char_count' => $this->inputTotalChars($input),
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
                $waitFromRetryAfterHeader = $e->retryAfterSeconds !== null;
                $waitSeconds = $e->retryAfterSeconds ?? $this->resolveBackoffSeconds($this->backoff);

                $this->logAiRetryScheduled($analysisId, $websiteAnalysisId, $e, $waitSeconds, $waitFromRetryAfterHeader);

                $record->update(['status' => 'pending']);
                $this->release($waitSeconds);

                // リトライ対象: まだ結果が確定していないため、markCompleted/
                // markFailed・進捗カスケードのいずれも呼ばない
                // (BaseWebsiteAnalysisJobのrelease()経路と同じ扱い)。
                return;
            }

            if ($e->isRetryable) {
                // 依頼U-3: $triesを使い切った(またはretryUntil()の期限切れで
                // このhandle()自体が呼ばれなかった場合はfailed()側、
                // ClassifiesJobFailureExceptions参照)ことが分かるログを残す
                // ―― 1回で諦めたのか粘ったのかが既存のログだけでは区別
                // できなかったため(依頼者指摘)。
                $this->logAiRetriesExhausted($analysisId, $websiteAnalysisId, $e);
            }

            $this->finalizeBrandWheelResult($record, $websiteAnalysis, ['status' => 'error', 'error_code' => $e->errorCode, 'error_message' => $e->getMessage(), 'input_hash' => $inputHash, 'input_truncated' => $input->inputTruncated, 'input_char_count' => $this->inputTotalChars($input)]);
            $this->completeAsFailed($pipeline, $jobRecord, $analysisId, $websiteAnalysisId, $e->getMessage());

            return;
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $result = $outcome->result;

        $this->finalizeBrandWheelResult($record, $websiteAnalysis, [
            'provider' => $result->provider,
            'model' => $result->model,
            'status' => 'success',
            'prompt_version' => $result->promptVersion,
            'axes' => array_map(fn ($axis) => $axis->toArray(), $result->axes),
            'core_value_readable' => $result->coreValue->readable,
            'core_value_evidence' => $result->coreValue->evidence,
            'key_message' => $result->keyMessage,
            'impression' => $result->impression,
            'positive_impression' => $result->positiveImpression,
            'negative_impression' => $result->negativeImpression,
            'quality_dimension_notes' => $result->qualityDimensionNotes,
            'cautions' => $result->cautions,
            'axis_state_counts' => $result->axisStateCounts,
            'is_mock' => $result->isMock,
            'input_hash' => $inputHash,
            'input_truncated' => $input->inputTruncated,
            'input_char_count' => $this->inputTotalChars($input),
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

        // 2026-08-17追加: このAnalysisの全BrandWheelAnalysisResult(自社・競合)が
        // 終端状態に達したら、改善提案(page6)AIの生成を1回だけdispatchする
        // (BrandWheelImprovementSuggestionDispatcher参照)。診断本体の進捗・
        // 完了判定には影響させない(判定後の副作用として呼ぶだけ)。
        app(BrandWheelImprovementSuggestionDispatcher::class)->dispatchIfReady($analysisId);
    }

    /**
     * ブランド・ホイール判定の終端ステータスを保存する唯一の入口
     * (2026-08-24追加、依頼者指定)。handle()内の全ての終端分岐
     * (insufficient_input確定・再利用キャッシュ命中・AI成功・AI失敗
     * (非リトライ)・provider未設定エラー)は、$record->update()を直接
     * 呼ばずここを経由すること。保存直後に必ずmaybeConsumeLeadQuota()を
     * 呼ぶことで、「ステータスを保存したのに消費判定を素通りする」ことを
     * 構造的に起こせなくする(個々の分岐が消費判定を呼び忘れるリスクを、
     * 呼び出し側のテストで洗うのではなく、そもそも分岐できない形にする)。
     *
     * WEBSITE_ANALYSIS_NOT_FOUND(handle()冒頭)だけは例外 ―― この時点では
     * まだ$websiteAnalysisを解決できていないため、この入口を経由しない
     * (診断回数消費の対象となる自社サイト情報がそもそも無い)。
     *
     * リトライ待ちのrelease()(status='pending'への一時更新)も対象外 ――
     * まだ終端に達していないため、ここを経由せず直接$record->update()する。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function finalizeBrandWheelResult(BrandWheelAnalysisResultRecord $record, WebsiteAnalysis $websiteAnalysis, array $attributes): void
    {
        $record->update($attributes);

        $this->maybeConsumeLeadQuota($websiteAnalysis, $record->fresh());
    }

    /**
     * 2026-08-19追加: analysis_id=45/website_analysis_id=93の障害調査用の
     * 一時的な診断ログ。fetch_recruit_page/render_pageは完了しており、本番
     * tinkerからは3ファイルとも存在確認できているにも関わらず、この
     * Job実行時にはsource_pagesが両方ともunreadableになる不具合が観測された
     * ため、「書き込み時のhostname」と「このJob実行時のhostname」が一致するか
     * を本番ログから直接突き合わせられるようにする(Renderが複数インスタンス
     * でローカルディスクを共有できていない疑いの検証)。原因確定後に削除・
     * 縮小を検討する(恒久的な監視ログとして残すかは別途判断)。
     */
    private function logStorageDiagnostics(int $analysisId, int $websiteAnalysisId): void
    {
        $disk = Storage::disk('analysis');
        $diskRoot = (string) config('filesystems.disks.analysis.root');

        $homepage = AnalysisPage::query()
            ->where('website_analysis_id', $websiteAnalysisId)
            ->where('page_type', PageType::Homepage)
            ->first();
        $recruit = AnalysisPage::query()
            ->where('website_analysis_id', $websiteAnalysisId)
            ->where('page_type', PageType::Recruit)
            ->first();

        Log::info('Brand wheel analysis: storage diagnostics at job start', [
            'analysis_id' => $analysisId,
            'website_analysis_id' => $websiteAnalysisId,
            'hostname' => gethostname(),
            'analysis_disk_root' => $diskRoot,
            'homepage_raw_path' => $homepage?->raw_html_path,
            'homepage_raw_exists' => $homepage?->raw_html_path !== null ? $disk->exists($homepage->raw_html_path) : null,
            'homepage_rendered_path' => $homepage?->rendered_html_path,
            'homepage_rendered_exists' => $homepage?->rendered_html_path !== null ? $disk->exists($homepage->rendered_html_path) : null,
            'recruit_raw_path' => $recruit?->raw_html_path,
            'recruit_raw_exists' => $recruit?->raw_html_path !== null ? $disk->exists($recruit->raw_html_path) : null,
        ]);
    }

    /**
     * リード診断の実行回数消費(#B-2、2026-08-22。2026-08-24に判定基準を
     * 変更)。自社サイト(WebsiteAnalysis.website.is_primary=true)かつ、
     * そのAnalysisがリードセッション由来(project.lead_session_id あり)で
     * ある場合のみ対象とする ―― 社内向けの通常診断・比較サイト側の判定には
     * 一切影響しない。
     *
     * 「成功」の定義(2026-08-24変更、2026-08-25に閾値を引き上げ): $record
     * (保存直後の最新状態)がBrandWheelReportEligibility::isReportable()を
     * 満たすこと(status='success'かつmatched件数が
     * config('brand_wheel.report_eligibility_min_matched')(既定6)以上)に
     * 加え、トップページのHTTPステータスが2xxであること。以前は採用ページ+
     * トップページ本文の文字数閾値
     * (isInputInsufficient()の否定)のみを基準にしていたが、これだと本文は
     * 十分でもAI呼び出し自体が失敗(error)した場合に消費だけが先に確定して
     * しまい、レポートを渡せないまま診断回数だけが失われる不具合があった
     * (8/24 shinkin.co.jp、依頼者指摘)。insufficient_input・error・
     * matched=0(no_matched_content)はいずれも「白紙」でレポート生成の
     * 対象外のため消費もしない ―― リトライしても結果が変わらない
     * insufficient_inputも同様に扱う点に注意(サイトの中身が薄いこと自体は
     * リトライで直らないが、無制限リトライの歯止めはLeadSession.
     * attempts_used側で別途かける、依頼者指定)。
     *
     * GenerateBrandWheelAnalysisJobはAI呼び出しのレート制限等でリトライ
     * (依頼U、2026-08-26): 最大試行回数はconfig('services.brand_wheel_ai.
     * job_tries')で調整可能、既定4回)されることがあり、そのたびにこの
     * メソッドも再実行される。
     * Analysis.lead_quota_consumed_at への「nullの行だけを対象にした条件付き
     * UPDATE」で一度だけ勝者を決めることで、二重消費を防ぐ
     * (LeadSessionService::recordConsultationRequested()と同じ方式)。
     * このJob(および入力を組み立てるBrandWheelAnalysisInputFactory)は
     * 本来Leadサブシステムに依存しない設計だが、消費タイミングをここへ
     * 統合することは依頼者との合意事項(#B-2設計確認)。
     */
    private function maybeConsumeLeadQuota(WebsiteAnalysis $websiteAnalysis, BrandWheelAnalysisResultRecord $record): void
    {
        if (! app(BrandWheelReportEligibility::class)->isReportable($record)) {
            return;
        }

        if (! (bool) $websiteAnalysis->website?->is_primary) {
            return;
        }

        $analysis = Analysis::query()->with('project')->find($websiteAnalysis->analysis_id);
        $leadSessionId = $analysis?->project?->lead_session_id;

        if ($analysis === null || $leadSessionId === null) {
            return;
        }

        $homepage = AnalysisPage::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('page_type', PageType::Homepage)
            ->first();

        $httpStatusOk = $homepage !== null
            && $homepage->http_status !== null
            && $homepage->http_status >= 200
            && $homepage->http_status < 300;

        if (! $httpStatusOk) {
            return;
        }

        $updated = Analysis::query()
            ->whereKey($analysis->id)
            ->whereNull('lead_quota_consumed_at')
            ->update(['lead_quota_consumed_at' => now()]);

        if ($updated === 0) {
            // 既に消費済み(このJobのリトライによる再実行)。二重消費しない。
            return;
        }

        $leadSession = LeadSession::find($leadSessionId);

        if ($leadSession !== null) {
            app(LeadSessionService::class)->recordAnalysisStarted($leadSession);
        }
    }

    /**
     * 採用ページ本文・トップページ本文の合計文字数が
     * config('brand_wheel.insufficient_input_min_total_chars')未満かどうか。
     * 見出し・ナビゲーションラベルは判定に含めない(具体的な裏づけ根拠は
     * 本文からしか得られないため)。
     */
    private function isInputInsufficient(BrandWheelAnalysisInput $input): bool
    {
        return $this->inputTotalChars($input) < (int) config('brand_wheel.insufficient_input_min_total_chars', 200);
    }

    /**
     * 依頼P-1(2026-08-25): brand_wheel_analysis_results.input_char_countの
     * 算出式。isInputInsufficient()と同じ値(採用ページ本文+トップページ
     * 本文の合計文字数)を、意味の異なる2箇所で別々に計算しないよう
     * ここへ切り出した。
     */
    private function inputTotalChars(BrandWheelAnalysisInput $input): int
    {
        return mb_strlen($input->recruitPageBodyText) + mb_strlen($input->homepageBodyText);
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

        [$errorCode, $message] = $this->classifyJobFailureException($exception);

        $record->update([
            'status' => 'error',
            'error_code' => $errorCode->value,
            'error_message' => $exception?->getMessage(),
        ]);

        // Laravelのキュー基盤自身がJobを終了させた場合(timeout超過・tries使い
        // 切り後の再スケジュール失敗)、handle()内のtry/catchを経由せずここへ
        // 直接来る。ここでmarkFailed()しておかないとAnalysisJob.statusが
        // runningのまま残り、maybeFinalizeWebsiteAnalysis()の「全Job終端待ち」が
        // 完了しない(BaseWebsiteAnalysisJob::failed()と同じ理由、2026-07-25の
        // 本番障害の再発防止)。$errorCode/$messageは上のclassifyJobFailure
        // Exception()と同じ分類結果を使う(以前は常にJobTimeout固定だったため、
        // 8/16〜17の障害でpositive_impressionカラム欠落によるQueryExceptionが
        // JOB_TIMEOUTとして記録され調査をミスリードした、2026-08-24修正)。
        $pipeline = app(AnalysisPipeline::class);
        $jobRecord = AnalysisJobRecord::query()
            ->where('analysis_id', $record->analysis_id)
            ->where('website_analysis_id', $record->website_analysis_id)
            ->where('job_type', JobType::GenerateBrandWheelAnalysis)
            ->first();

        if ($jobRecord === null || $jobRecord->status->isTerminal()) {
            return;
        }

        $pipeline->markFailed($jobRecord, $errorCode, $message);
        $this->cascadeProgress($pipeline, $record->analysis_id, $record->website_analysis_id);
    }
}
