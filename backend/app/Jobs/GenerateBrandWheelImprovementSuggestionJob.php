<?php

namespace App\Jobs;

use App\Models\Analysis;
use App\Models\BrandWheelImprovementSuggestion;
use App\Models\WebsiteAnalysis;
use App\Services\BrandWheel\BrandWheelAnalysisException;
use App\Services\BrandWheel\BrandWheelComparisonSufficiency;
use App\Services\BrandWheel\BrandWheelEvidenceLookupBuilder;
use App\Services\BrandWheel\BrandWheelImprovementSuggestionInputFactory;
use App\Services\BrandWheel\BrandWheelImprovementSuggestionProviderFactory;
use App\Services\BrandWheel\BrandWheelLeadResponseComposer;
use App\Services\BrandWheel\BrandWheelSubElementComparisonComposer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Analysis単位(自社×競合の比較単位)で改善提案(page6)のAI提言を生成する。
 * GenerateBrandWheelAnalysisJobと同じ設計方針(明確なエラーとして記録して
 * 正常終了する、レート制限のみリトライ)だが、以下の点が異なる:
 *
 * - AnalysisPipeline/AnalysisJobの進捗管理には参加しない(このJobは診断本体の
 *   完了より後、BrandWheelImprovementSuggestionDispatcherから1回だけ
 *   dispatchされる独立したボーナスコンテンツ生成のため。診断自体の完了判定・
 *   進捗バーには影響させない)。
 * - 入力データ(BrandWheelImprovementSuggestionInput)は既に評価済みの
 *   BrandWheelAnalysisResultから決定的に組み立てるため、input_hashによる
 *   キャッシュ再利用は行わない(analysis_idにunique制約があり、そもそも
 *   1診断につき1回しか生成されない)。
 *
 * タイムアウト不変条件はGenerateBrandWheelAnalysisJobと同じ理由で動的に計算する。
 */
class GenerateBrandWheelImprovementSuggestionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout;

    /** @var int|array<int, int> */
    public $backoff = [30];

    public $uniqueFor;

    public function __construct(
        public readonly int $suggestionId,
    ) {
        $this->timeout = ((int) config('services.brand_wheel_ai.timeout', 60)) + 30;
        $this->uniqueFor = $this->timeout * 3;
    }

    public function uniqueId(): string
    {
        return "brand-wheel-improvement-suggestion:{$this->suggestionId}";
    }

    public function handle(
        BrandWheelLeadResponseComposer $wheelComposer,
        BrandWheelSubElementComparisonComposer $comparisonComposer,
        BrandWheelImprovementSuggestionInputFactory $inputFactory,
        BrandWheelEvidenceLookupBuilder $evidenceLookupBuilder,
        BrandWheelComparisonSufficiency $comparisonSufficiency,
    ): void {
        $suggestion = BrandWheelImprovementSuggestion::find($this->suggestionId);

        if ($suggestion === null) {
            return;
        }

        $analysis = Analysis::find($suggestion->analysis_id);

        if ($analysis === null) {
            $suggestion->update(['status' => 'error', 'error_code' => 'ANALYSIS_NOT_FOUND', 'error_message' => '対象のAnalysisが見つかりません。']);

            return;
        }

        $suggestion->update(['status' => 'running']);

        $analysis->loadMissing([
            'websiteAnalyses.website',
            'websiteAnalyses.brandWheelAnalysisResults' => fn ($query) => $query->latest('id')->limit(1),
        ]);

        $selfWebsiteAnalysis = $analysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => (bool) $wa->website?->is_primary);
        $competitorWebsiteAnalysis = $analysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => ! (bool) $wa->website?->is_primary);

        $selfRecord = $selfWebsiteAnalysis?->brandWheelAnalysisResults->first();
        $competitorRecord = $competitorWebsiteAnalysis?->brandWheelAnalysisResults->first();

        $selfWheel = $selfWebsiteAnalysis !== null ? $wheelComposer->compose($selfRecord, $selfWebsiteAnalysis->website) : null;
        $competitorWheel = $competitorWebsiteAnalysis !== null ? $wheelComposer->compose($competitorRecord, $competitorWebsiteAnalysis->website) : null;

        $selfReadable = ($selfWheel['status'] ?? null) === 'success' && ($selfWheel['axes'] ?? []) !== [];
        $competitorReadable = ($competitorWheel['status'] ?? null) === 'success' && ($competitorWheel['axes'] ?? []) !== [];

        if (! $selfReadable) {
            // BrandWheelImprovementSuggestionDispatcher側で既に同じ判定を
            // 行っているが、dispatch後に状態が変わる可能性もゼロではないため
            // ここでも安全側にもう一度確認する。
            $suggestion->update(['status' => 'error', 'error_code' => 'SELF_NOT_READABLE', 'error_message' => '自社サイトの分析結果が読み取れないため、改善提案を生成できません。']);

            return;
        }

        // 2026-08-25追加(修正2): 自社の合計matched件数が閾値未満のときは、
        // 個別項目の提案が的外れになる(実物レポート32 ―― 4/24項目しか
        // 読み取れていないのに「重視する価値を書きましょう」という個別助言は
        // 成立しない)ため、AIを呼ばず「掲載する情報を増やす」定型文
        // (config('brand_wheel.one_point_messages.insufficient_content'))
        // のみを保存する。他のフィールド(reason/recommended_contents/
        // mid_term_action等)はnull/空配列のままとし、Blade/WordReport
        // Generator側の「AI未生成時は該当ブロックを出さない」既存仕様に
        // 委ねる。
        $selfTotalMatched = array_sum(array_column((array) $selfWheel['axes'], 'matched_count'));
        if (! $comparisonSufficiency->isSufficient($selfTotalMatched)) {
            $suggestion->update([
                'provider' => null,
                'model' => null,
                'status' => 'success',
                'prompt_version' => null,
                'one_point' => (string) config('brand_wheel.one_point_messages.insufficient_content'),
                'recommendation' => null,
                'focus_sub_element_keys' => [],
                'reason' => null,
                'recommended_contents' => [],
                'mid_term_action' => null,
                'quick_win' => null,
                'implementation_difficulty' => null,
                'candidate_impact' => null,
                'gap_closing' => [],
                'differentiation_opportunities' => [],
                'is_mock' => false,
                'input_hash' => null,
                'usage_input_tokens' => null,
                'usage_output_tokens' => null,
                'duration_ms' => 0,
                'error_code' => null,
                'error_message' => null,
                'generated_at' => now(),
            ]);

            return;
        }

        // 2026-08-25追加(修正1): 競合の合計matched件数が閾値未満のときは、
        // AIへ競合データを一切渡さない(competitorReadable=trueでも、
        // 1件程度の読み取り結果を根拠に「競合がこの点を強調しているため」
        // という比較の文章をAIが書いてしまう不具合への対応、実物レポート32)。
        // $hasCompetitorをfalseにすると、BrandWheelImprovementSuggestion
        // InputFactoryは競合関連の配列を一切含めず、プロバイダのプロンプトも
        // 既存の「比較サイトのデータはありません」という自社単独モード
        // (競合が本当に無いケースと同じ分岐)に切り替わる。
        $competitorTotalMatched = $competitorReadable ? array_sum(array_column((array) $competitorWheel['axes'], 'matched_count')) : 0;
        $hasSufficientCompetitor = $competitorReadable && $comparisonSufficiency->isSufficient($competitorTotalMatched);

        $comparisonItems = $comparisonComposer->compose((array) $selfWheel['axes'], $competitorReadable ? (array) $competitorWheel['axes'] : []);
        $groupTotals = $competitorReadable ? $comparisonComposer->groupTotals($comparisonItems) : [];

        $input = $inputFactory->build(
            $comparisonItems,
            $evidenceLookupBuilder->build($selfRecord),
            $competitorReadable ? $evidenceLookupBuilder->build($competitorRecord) : [],
            $groupTotals,
            $hasSufficientCompetitor,
            // 2026-08-20追加: 差別化テーマ選定に自社の既存ブランド文脈を
            // 考慮させるための自社強みデータ(依頼者指摘)。key_message/
            // positive_impressionはBrandWheelLeadResponseComposer::compose()が
            // 既に検証済みの値、core_value_evidenceはcore_value_readable=true
            // のときのみ渡す(未検証のCore Valueを新たに作らない)。
            $selfWheel['key_message'] ?? null,
            $selfWheel['positive_impression'] ?? null,
            $selfRecord?->core_value_readable === true ? $selfRecord->core_value_evidence : null,
        );

        try {
            $provider = app(BrandWheelImprovementSuggestionProviderFactory::class)->make();
        } catch (BrandWheelAnalysisException $e) {
            $suggestion->update(['status' => 'error', 'error_code' => $e->errorCode, 'error_message' => $e->getMessage()]);

            return;
        }

        $started = microtime(true);

        try {
            $outcome = $provider->analyze($input);
        } catch (BrandWheelAnalysisException $e) {
            if ($e->isRetryable && $this->attempts() < $this->tries && $this->job !== null) {
                $suggestion->update(['status' => 'pending']);
                $this->release($e->retryAfterSeconds ?? $this->backoff[0]);

                return;
            }

            $suggestion->update(['status' => 'error', 'error_code' => $e->errorCode, 'error_message' => $e->getMessage()]);

            return;
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $result = $outcome->result;

        $suggestion->update([
            'provider' => $result->provider,
            'model' => $result->model,
            'status' => 'success',
            'prompt_version' => $result->promptVersion,
            'one_point' => $result->onePoint,
            'recommendation' => $result->recommendation,
            'focus_sub_element_keys' => $result->focusSubElementKeys,
            'reason' => $result->reason,
            'recommended_contents' => $result->recommendedContents,
            'mid_term_action' => $result->midTermAction,
            'quick_win' => $result->quickWin,
            'implementation_difficulty' => $result->implementationDifficulty,
            'candidate_impact' => $result->candidateImpact,
            'gap_closing' => $result->gapClosing,
            'differentiation_opportunities' => $result->differentiationOpportunities,
            'is_mock' => $result->isMock,
            'input_hash' => hash('sha256', json_encode($input->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
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
        $suggestion = BrandWheelImprovementSuggestion::find($this->suggestionId);

        $suggestion?->update([
            'status' => 'error',
            'error_code' => 'BRAND_WHEEL_IMPROVEMENT_SUGGESTION_JOB_FAILED',
            'error_message' => $exception?->getMessage(),
        ]);
    }
}
