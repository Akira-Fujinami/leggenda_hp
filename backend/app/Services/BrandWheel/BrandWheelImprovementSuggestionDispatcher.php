<?php

namespace App\Services\BrandWheel;

use App\Jobs\GenerateBrandWheelImprovementSuggestionJob;
use App\Models\BrandWheelAnalysisResult;
use App\Models\BrandWheelImprovementSuggestion;
use App\Models\WebsiteAnalysis;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

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
 *
 * 依頼AJ-1(2026-08-28、本番analysis_id=109): 従来は「保留中(非終端)の
 * BrandWheelAnalysisResultが無いこと」だけで判定していたが、これは
 * 「必要な行が全部そろっている」ことを意味しない ―― BrandWheelAnalysisResult
 * は各WebsiteAnalysisの独立したクロール/レンダリングパイプライン
 * (AnalysisPipeline::dispatchBrandWheelAnalysisAfterCrawl())の終端で
 * website_analysisごとに個別に作られる(診断開始時に自社・競合ぶんまとめて
 * 作られるわけではない)。自社の判定が先に終端に達し、競合の行がまだ
 * 「作られてすらいない」瞬間にこのJobが呼ばれると、存在しない行は
 * whereNotIn(...)->exists()の対象にならないため「保留中は無い」と誤判定し、
 * 競合ゼロの状態で改善提案の生成が始まっていた(実際に本番で確認・
 * dispatchIfReady()を直接呼ぶ再現テストでも同じ壊れた状態
 * (focus_items_reason_sub_names=[]・reason=null・mid_term_action=null)を
 * 確認済み)。この診断の対象WebsiteAnalysis件数と同じ数のBrandWheelAnalysisResult
 * が存在することを先に確認し、その後で従来どおり全件終端かを確認する
 * (順序を入れ替えるだけで、終端判定・冪等性の仕組み自体は無改修)。
 */
class BrandWheelImprovementSuggestionDispatcher
{
    /**
     * @var list<string>
     */
    private const TERMINAL_STATUSES = ['success', 'insufficient_input', 'error'];

    public function dispatchIfReady(int $analysisId): void
    {
        // 依頼AK-1(2026-08-28): website_analysesは(analysis_id, website_id)に
        // 一意制約があるため、このcount()は常にこの診断のサイト数(=distinct
        // website_id数)と一致する ―― 同一websiteに対する重複行は構造的に
        // 作れない。
        $websiteAnalysisCount = WebsiteAnalysis::query()->where('analysis_id', $analysisId)->count();

        // brand_wheel_analysis_resultsは同一website_analysis_idに複数行を
        // 持ちうる設計(--force再実行での比較用、AnalysisPipeline::
        // dispatchBrandWheelAnalysisAfterCrawl()のdocblock参照、一意制約は
        // 意図的に張っていない)。行数ではなくdistinctなwebsite_analysis_id数で
        // 数えないと、例えば自社だけ--forceで2行になった場合に
        // 「2行(自社)+0行(競合)=2行」が診断のサイト数(2)と一致してしまい、
        // 競合の行が1件も無いままガードを通過してしまう(依頼者指摘)。
        $brandWheelResultWebsiteAnalysisCount = BrandWheelAnalysisResult::query()
            ->where('analysis_id', $analysisId)
            ->distinct()
            ->count('website_analysis_id');

        if ($brandWheelResultWebsiteAnalysisCount < $websiteAnalysisCount) {
            // まだ一部のWebsiteAnalysisについて、BrandWheelAnalysisResult
            // 行自体が作られていない(依頼AJ-1 ―― 存在しない行は「保留中」
            // として数えられないため、この確認を先に行う必要がある)。
            // 依頼AK-1: 多い場合(--force再実行で件数が診断のサイト数を
            // 超える場合)はガードしない ―― `!==`にすると--force再実行が
            // 正常に完了できなくなるため、不足のみを見る。
            return;
        }

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
        } catch (QueryException $e) {
            // 想定しているのはanalysis_idの一意制約違反(23505、別プロセスが
            // 同時に作成済み)のみ。それ以外(カラム欠落等の本当のスキーマ
            // 不一致)を同じ握りつぶしに含めると無言で通過してしまう
            // (2026-08-24修正、8月の障害の再発防止)。呼び出し元
            // (GenerateBrandWheelAnalysisJob::cascadeProgress())は「改善提案の
            // 生成は判定後の副作用であり、診断本体の進捗・完了判定には
            // 影響させない」方針のため、ここでも再スローはせずログのみに
            // 留める。
            if ((string) $e->getCode() !== '23505') {
                Log::error('Failed to create brand wheel improvement suggestion row', [
                    'analysis_id' => $analysisId,
                    'sqlstate' => $e->getCode(),
                    'exception_message' => $e->getMessage(),
                ]);
            }

            return;
        }

        // 2026-08-18: 明示的なonQueue()指定が無く、デフォルトキュー('database'
        // 接続の既定 'default')へdispatchされていた。本番(Render)の埋め込み
        // queue workerは QUEUE_WORKER_QUEUES(既定
        // analysis,external-api,analysis-heavy,ai,reports,notifications)に
        // 列挙されたキューしか処理せず、'default'は含まれていない ―― この
        // ためJobが本番で一切実行されず、改善提案が常に決定的フォールバック
        // 文言のまま表示され続けるという実害のある不具合だった(実PDF確認と
        // Render Worker設定の突き合わせで判明)。兄弟Job(GenerateBrandWheel
        // AnalysisJob、OpenAI呼び出し)と同じ'ai'キューに揃える。
        GenerateBrandWheelImprovementSuggestionJob::dispatch($suggestion->id)->onQueue('ai');
    }

    /**
     * 依頼AK-2(2026-08-28): dispatchIfReady()は「まだ材料がそろっていない」
     * ことをログ無しで何度でも早期returnする(正常な待機のたびにログを
     * 出すとノイズになるため、依頼者指定でここは変えない)。しかし
     * dispatchIfReady()の呼び出しの起点はGenerateBrandWheelAnalysisJobの
     * cascadeProgress()の1箇所だけであり、あるサイトのBrandWheelAnalysisResult
     * が(クロール系ジョブのOOM等でfailed()すら経ずに)最後まで作られなければ、
     * そのサイトのGenerateBrandWheelAnalysisJob自体が走らず、dispatchIfReady()は
     * 二度と呼ばれない ―― 「必要な行がそろうまで待つ」という正しい判断が、
     * 行が永久にそろわない場合は「二度と生成されない」まま沈黙する。
     *
     * この関数は、Analysis全体が終端に達した時点(FinalizeAnalysisJob、
     * ShouldBeUniqueで1診断につき1回だけ実行される)で1回だけ呼ぶことを
     * 想定する ―― dispatchIfReady()側では呼ばない(要件「早期returnごとに
     * 出さないこと」を、呼び出し場所を分けることで機械的に満たす)。
     *
     * 診断完了時点でBrandWheelImprovementSuggestionが存在しない場合に
     * 構造化ログを1件出す。診断対象のWebsiteAnalysis件数・実際に
     * BrandWheelAnalysisResultが存在するwebsite_analysis_idの件数・
     * 欠けているwebsite_analysis_id(IDのみ、URL・会社名は含めない)を
     * 併記する。本文・プロンプト・APIキー・顧客情報は含めない。
     *
     * 依頼AL-1(2026-08-28): 存在しない理由は2種類あり、性質が異なるため
     * ログレベル・本文を分ける。
     * - missing_website_analysis_idsが非空: あるサイトのBrandWheel
     *   AnalysisResultが最後まで作られなかった異常(依頼AK-2が見えるように
     *   したかったのはこちら) ―― warning。
     * - missing_website_analysis_idsが空: 行はそろっているが、自社が
     *   読み取れず(insufficient_input・axesが空・totalMatched===0)意図的に
     *   生成しなかった正常系(依頼X以来の想定済みの結末、レポートの
     *   Skipped状態としてDB・管理画面から既に見える) ―― warningのまま
     *   出し続けると、この珍しくない正常系のたびに鳴り続けて無視される
     *   ようになり、本当に見たい異常側まで一緒に見過ごされる(依頼者指摘)。
     *   infoに落とし、本文も「行は揃っているが自社が読み取れず生成しな
     *   かった」と分かるものにする(warning側と同じ本文のままレベルだけ
     *   変えると、ログ集約側で本文による絞り込みができないため)。
     */
    public function logIfSuggestionMissingAfterAnalysisCompletion(int $analysisId): void
    {
        $websiteAnalysisIds = WebsiteAnalysis::query()->where('analysis_id', $analysisId)->pluck('id');

        if ($websiteAnalysisIds->isEmpty()) {
            return;
        }

        if (BrandWheelImprovementSuggestion::query()->where('analysis_id', $analysisId)->exists()) {
            return;
        }

        $presentWebsiteAnalysisIds = BrandWheelAnalysisResult::query()
            ->where('analysis_id', $analysisId)
            ->distinct()
            ->pluck('website_analysis_id');

        $missingWebsiteAnalysisIds = $websiteAnalysisIds->diff($presentWebsiteAnalysisIds)->values();

        $context = [
            'analysis_id' => $analysisId,
            'website_analysis_count' => $websiteAnalysisIds->count(),
            'brand_wheel_result_distinct_website_analysis_count' => $presentWebsiteAnalysisIds->count(),
            'missing_website_analysis_ids' => $missingWebsiteAnalysisIds->values()->all(),
        ];

        if ($missingWebsiteAnalysisIds->isNotEmpty()) {
            Log::warning('Analysis completed without a brand wheel improvement suggestion: a BrandWheelAnalysisResult row is missing for at least one website', $context);

            return;
        }

        Log::info('Analysis completed without a brand wheel improvement suggestion: all rows exist, likely skipped because self was not readable', $context);
    }
}
