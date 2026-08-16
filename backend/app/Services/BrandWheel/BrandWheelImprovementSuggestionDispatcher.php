<?php

namespace App\Services\BrandWheel;

use App\Jobs\GenerateBrandWheelImprovementSuggestionJob;
use App\Models\BrandWheelAnalysisResult;
use App\Models\BrandWheelImprovementSuggestion;
use Illuminate\Database\QueryException;

/**
 * 改善提案(page6)AIの生成タイミングを判定し、必要ならJobをdispatchする。
 * BrandWheelCompletionNotifierと同じ「side-effectとしてJobから呼ばれる」
 * パターンだが、判定対象が異なる:
 *
 * - BrandWheelCompletionNotifierは「自社の生成完了 かつ 相談意思表示あり」で
 *   メール送信を判定する(自社側のみ見る)。
 * - こちらは「診断(Analysis)に紐づく全BrandWheelAnalysisResult(自社・競合)が
 *   終端状態(success/insufficient_input/error)に達したか」を見る ―― 改善提案は
 *   自社×競合の比較単位の成果物のため、両方の生成が終わるまで待つ必要がある
 *   (競合が無い診断では自社のみが対象)。
 *
 * 自社が読み取れない(status!=='success'またはaxesが空)場合は、提言の材料が
 * 無いため生成しない(GenerateBrandWheelImprovementSuggestionJob::handle()側
 * でも同じ判定を行うが、そもそも無駄なJobをdispatchしないためここでも判定する)。
 *
 * 冪等性: brand_wheel_improvement_suggestions.analysis_idにunique制約がある
 * ため、複数のBrandWheelAnalysisResultが同時に終端状態へ達しても(自社・競合の
 * Jobが並行実行された場合)、最初に行を作成できた1回だけがJobをdispatchする
 * (2回目以降はQueryExceptionを捕捉して何もしない)。
 */
class BrandWheelImprovementSuggestionDispatcher
{
    /**
     * @var list<string>
     */
    private const TERMINAL_STATUSES = ['success', 'insufficient_input', 'error'];

    public function dispatchIfReady(int $analysisId): void
    {
        $hasPending = BrandWheelAnalysisResult::query()
            ->where('analysis_id', $analysisId)
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->exists();

        if ($hasPending) {
            // まだ自社/競合のいずれかが生成中。両方の終端を待つ。
            return;
        }

        $selfRecord = BrandWheelAnalysisResult::query()
            ->where('analysis_id', $analysisId)
            ->whereHas('websiteAnalysis.website', fn ($q) => $q->where('is_primary', true))
            ->first();

        if ($selfRecord === null || $selfRecord->status !== 'success' || empty($selfRecord->axes)) {
            // 自社が読み取れない場合、改善提案の材料が無いため生成しない。
            return;
        }

        // BrandWheelLeadResponseComposer::resolveStatus()と同じ判定
        // (「全24項目0件」は'no_matched_content'として扱われ、実際には
        // '読み取れる'状態ではない) ―― ここでも同じ基準を使い、材料が
        // 無い自社サイトに対してJobを無駄にdispatchしない(GenerateBrandWheel
        // ImprovementSuggestionJob側もselfReadable判定で同じ結論に達するが、
        // ここで弾く方が1往復分のキュー処理を節約できる)。
        $totalMatched = collect((array) ($selfRecord->axes ?? []))
            ->sum(fn (array $axis) => count($axis['matched_sub_elements'] ?? []));

        if ($totalMatched === 0) {
            return;
        }

        if (BrandWheelImprovementSuggestion::query()->where('analysis_id', $analysisId)->exists()) {
            // 既に生成済み/生成中。
            return;
        }

        try {
            $suggestion = BrandWheelImprovementSuggestion::create([
                'analysis_id' => $analysisId,
                'status' => 'pending',
            ]);
        } catch (QueryException) {
            // analysis_idのunique制約違反 ―― 別プロセスが同時に作成済み。
            return;
        }

        GenerateBrandWheelImprovementSuggestionJob::dispatch($suggestion->id);
    }
}
