<?php

namespace App\Jobs;

use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult as BrandWheelAnalysisResultRecord;
use App\Models\WebsiteAnalysis;
use App\Services\BrandWheel\BrandWheelAnalysisException;
use App\Services\BrandWheel\BrandWheelAnalysisInputFactory;
use App\Services\BrandWheel\BrandWheelAnalysisProvider;
use App\Services\BrandWheel\BrandWheelAnalysisProviderFactory;
use App\Services\BrandWheel\Data\BrandWheelAnalysisInput;
use App\Services\Lead\LeadNotificationService;
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
 * - 事前に相談ボタンのController(Task #96)がbrand_wheel_analysis_resultsへ
 *   status=pendingの行を作成し、そのIDだけを受け取る。
 * - 同一website_analysis_id×同一input_hashで既に成功している結果があれば、
 *   APIを再度呼ばずそれを複製する(冪等・コスト削減)。
 * - Provider未設定/認証エラー等は永久に待ち続けず、明確なエラーとして
 *   記録して正常終了する(このJob自体がfailedになるのは想定外の例外のみ)。
 *   これにより、このJobの成否が相談受付フロー全体(1通目メール等)を
 *   ブロックすることはない。
 * - レート制限のみリトライ対象とし、認証エラーはリトライしない。
 *
 * タイムアウト不変条件(2026-07-24の本番障害の再発防止): $timeoutは
 * 必ずAI呼び出しのHTTPタイムアウト(services.brand_wheel_ai.timeout)+30秒以上に
 * なるよう、固定値ではなくconstructorで動的に計算する ―― 運用でタイムアウト値を
 * 変更しても、この不変条件が自動的に保たれるようにするため。
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

    public function handle(BrandWheelAnalysisInputFactory $inputFactory): void
    {
        $record = BrandWheelAnalysisResultRecord::find($this->brandWheelAnalysisResultId);

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
            $record->update(['status' => 'error', 'error_code' => 'BRAND_WHEEL_INPUT_BUILD_FAILED', 'error_message' => $e->getMessage()]);

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

            $this->sendCompletionNotificationIfNeeded($record, $websiteAnalysis);

            return;
        }

        try {
            $provider = app(BrandWheelAnalysisProviderFactory::class)->make();
        } catch (BrandWheelAnalysisException $e) {
            $record->update(['status' => 'error', 'error_code' => $e->errorCode, 'error_message' => $e->getMessage()]);

            return;
        }

        $inputHash = $this->computeInputHash($input, $provider);

        $reusable = BrandWheelAnalysisResultRecord::query()
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

            $this->sendCompletionNotificationIfNeeded($record, $websiteAnalysis);

            return;
        }

        $started = microtime(true);

        try {
            $outcome = $provider->analyze($input);
        } catch (BrandWheelAnalysisException $e) {
            if ($e->isRetryable && $this->attempts() < $this->tries && $this->job !== null) {
                $record->update(['status' => 'pending']);
                $this->release($e->retryAfterSeconds ?? $this->backoff[0]);

                return;
            }

            $record->update(['status' => 'error', 'error_code' => $e->errorCode, 'error_message' => $e->getMessage(), 'input_hash' => $inputHash, 'input_truncated' => $input->inputTruncated]);

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

        $this->sendCompletionNotificationIfNeeded($record, $websiteAnalysis);
    }

    /**
     * 2通目(ブランド・ホイール分析結果)メールを社内スタッフへ送る。
     * staff_notified_atで冪等性を保証する ―― Jobのリトライやキュー再処理で
     * 複数回送信されるのを防ぐ(2026-07-30の指摘)。送信に失敗した場合は
     * staff_notified_atを更新しない(nullのままにする)ことで、次回の実行
     * (再送)で再度送信を試みられるようにする。
     *
     * リードの会社名・担当者名・メールアドレスはこの時点でDBから読み出すが、
     * Job自身のプロパティ(シリアライズされてjobsテーブルに載る値)には
     * 一切保持しない ―― ローカル変数としてこのメソッド内で使い切るのみ。
     *
     * 社内スタッフ向け・リード企業向けは受信者・送信条件・内容がすべて
     * 独立したメールのため、staff_notified_at/lead_notified_atを別々に
     * 判定・更新する。片方の送信に失敗しても、成功した側を再送せず
     * 失敗した側だけ次回の実行で再送できる(2026-07-30の指摘)。
     */
    private function sendCompletionNotificationIfNeeded(BrandWheelAnalysisResultRecord $record, WebsiteAnalysis $websiteAnalysis): void
    {
        $analysis = Analysis::find($record->analysis_id);
        $analysis?->loadMissing(['project.leadSession', 'websiteAnalyses.website']);
        $leadSession = $analysis?->project?->leadSession;

        $websiteAnalysis->loadMissing('website');
        $targetUrl = (string) ($websiteAnalysis->website?->normalized_url ?? '');

        if ($record->staff_notified_at === null) {
            $staffSent = app(LeadNotificationService::class)->notifyBrandWheelAnalysisCompleted(
                $record,
                $leadSession?->company_name ?? '(不明)',
                $leadSession?->contact_name ?? '(不明)',
                $targetUrl,
            );

            if ($staffSent) {
                $record->update(['staff_notified_at' => now()]);
            }
        }

        if ($record->lead_notified_at === null) {
            $leadSent = app(LeadNotificationService::class)->notifyBrandWheelAnalysisCompletedToLead(
                $record,
                $leadSession?->email,
                $targetUrl,
            );

            if ($leadSent) {
                $record->update(['lead_notified_at' => now()]);
            }
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
        $record?->update([
            'status' => 'error',
            'error_code' => 'BRAND_WHEEL_JOB_FAILED',
            'error_message' => $exception?->getMessage(),
        ]);
    }
}
