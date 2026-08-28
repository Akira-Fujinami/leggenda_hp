<?php

namespace App\Services\Lead;

use App\Enums\AnalysisStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportGenerationStatus;
use App\Jobs\Report\GenerateLeadReportJob;
use App\Jobs\Report\WaitForBrandWheelImprovementSuggestionJob;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\BrandWheelImprovementSuggestion;
use App\Models\Report;
use App\Models\WebsiteAnalysis;
use App\Services\BrandWheel\BrandWheelReportEligibility;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * 依頼Y-3(2026-08-26): リード向けレポート(Word/PDF)の生成を、診断完了と
 * ほぼ同時に起動する。従来は`LeadAnalysisController::
 * maybeDispatchReportGeneration()`がフロントのポーリングリクエスト(結果
 * 画面への遷移後、初めて結果APIを叩いたタイミング)からしか呼ばれず、
 * 構造上「結果画面に遷移した時点では必ず未生成」になっていた
 * (2026-08-26本番、5件同時実行時のPDF生成OOMの調査で判明)。
 *
 * `dispatchIfReportable()`を`FinalizeAnalysisJob`(診断パイプラインの終端
 * 処理)から呼ぶことで、診断が完了した瞬間にレポート生成を起動できる。
 *
 * 【reportable=falseの場合はここでは何もしないことについて】
 * レポートに値しない(`BrandWheelReportEligibility::isReportable()`が
 * false)場合のReport行作成(status=Skipped)・リード本人/社内スタッフへの
 * 見送り通知(`LeadDiagnosisUnavailableNotification`)は、引き続き
 * `LeadAnalysisController::maybeDispatchReportGeneration()`
 * (リードのポーリングリクエスト経由)が行う ―― このクラスの
 * `dispatchIfReportable()`からは呼ばない。
 *
 * 理由: 見送り通知のメール本文には「もう一度お試しになる」ボタンがあり、
 * リード本人の生トークンを含む結果URL(`/lead/diagnose?token=...`)が必要。
 * `LeadSession`はセキュリティ上`token_hash`(一方向ハッシュ)しか永続化
 * しておらず、生トークンはリードが実際にHTTPリクエストを送ってきた
 * ときにしか手元に無い。診断パイプライン(HTTPリクエストの外)からは
 * 生トークンを再構成できないため、この経路では見送り通知を送れない。
 * したがって「reportable=falseのとき何もしない」は意図的な設計であり、
 * 見送り通知は引き続きコントローラ側のポーリング経由でのみ発生する
 * (1診断につき1回という既存の保証は、この関数を呼ばないことで維持される
 * ―― コントローラ側のReport::exists()チェック・通知ロジックは無改修)。
 *
 * 依頼AM-1(2026-08-28): `createPendingReportsAndDispatch()`に、改善提案
 * (BrandWheelImprovementSuggestion)が確定するまで待つガードを追加した
 * (`isBrandWheelImprovementSuggestionSettled()`参照)。GenerateLeadReportJobは
 * ViewModelを1回だけ組み立てるため、改善提案がまだpendingのまま組み立てると
 * 理由・中長期が入らないレポートが完成してしまう ―― 依頼AJ-1で改善提案の
 * 起動を「競合の行がそろうまで」正しく遅らせた結果、診断終端との時間差が
 * 実質1秒程度まで縮まりこのレースが表面化した(本番analysis_id=110)。
 * ガードは`createPendingReportsAndDispatch()`自体に置くことで、
 * `dispatchIfReportable()`経由(FinalizeAnalysisJob)・
 * `LeadAnalysisController::maybeDispatchReportGeneration()`経由
 * (リードのポーリング、こちらも同じ`createPendingReportsAndDispatch()`を
 * 直接呼ぶ)のどちらからでも同じガードがかかる。
 */
class LeadReportDispatchService
{
    public function __construct(private readonly BrandWheelReportEligibility $eligibility) {}

    /**
     * 自社サイト(is_primary=true)の最新BrandWheelAnalysisResultを取得する。
     * GenerateBrandWheelAnalysisJob::maybeConsumeLeadQuota()と同じ「自社
     * サイトのみを見る」スコープ(競合サイト側の結果は一切影響させない)。
     * 元はLeadAnalysisControllerの同名privateメソッドだったものをここへ
     * 集約した(コントローラとこのクラスの両方から同じ判定を使うため)。
     */
    public function selfBrandWheelResult(Analysis $analysis): ?BrandWheelAnalysisResult
    {
        $selfWebsiteAnalysis = WebsiteAnalysis::query()
            ->where('analysis_id', $analysis->id)
            ->whereHas('website', fn ($q) => $q->where('is_primary', true))
            ->first();

        if ($selfWebsiteAnalysis === null) {
            return null;
        }

        return BrandWheelAnalysisResult::query()
            ->where('website_analysis_id', $selfWebsiteAnalysis->id)
            ->latest('id')
            ->first();
    }

    /**
     * $analysisが診断完了/一部完了(completed/partial)、リード診断
     * (LeadSessionを持つProject配下)、かつレポートに値する結果を持つ場合
     * のみ、Report行(pending)を作成しGenerateLeadReportJobを起動する。
     * それ以外の場合は何もしない(reportable=falseの扱いはクラスdocblock
     * 参照)。
     *
     * `Report::exists()`による重複作成防止・`(analysis_id, format)`の
     * unique制約違反(23505)の握りつぶしは、`LeadAnalysisController::
     * maybeDispatchReportGeneration()`と共有する(このクラスと同じ
     * `createPendingReportsAndDispatch()`を両方から呼ぶ)ため、
     * どちらが先に実行されても安全 ―― コントローラ側の呼び出しは
     * このパイプライン起動が何らかの理由で走らなかった場合の安全網として
     * そのまま残す(判定条件は無改修)。
     */
    public function dispatchIfReportable(Analysis $analysis): void
    {
        if (! in_array($analysis->status, [AnalysisStatus::Completed, AnalysisStatus::Partial], true)) {
            return;
        }

        if ($analysis->project?->leadSession === null) {
            return;
        }

        if (Report::query()->where('analysis_id', $analysis->id)->exists()) {
            return;
        }

        $selfResult = $this->selfBrandWheelResult($analysis);

        if (! $this->eligibility->isReportable($selfResult)) {
            return;
        }

        $this->createPendingReportsAndDispatch($analysis);
    }

    /**
     * 依頼AM-1(2026-08-28、本番analysis_id=110): GenerateLeadReportJobは
     * ViewModelを1回だけ組み立ててWord/PDF両方に使い回す(GenerateLeadReportJob
     * 参照)。改善提案(page6のAI、BrandWheelImprovementSuggestion)が
     * まだpendingのままViewModelを組み立てると、理由・中長期が入らないまま
     * レポートが完成してしまう ―― 依頼AJ-1で改善提案の起動条件を
     * 「競合の行がそろうまで待つ」ように直した結果、起動タイミングが
     * 診断終端の直前(以前は自社終端の直後で約27秒の余裕があった)まで
     * 遅くなり、このレースが実際に表面化した(本番analysis_id=110で
     * 確認、AF-2の理由生成は正常に動いていたがレポート組み立てのほうが
     * 早すぎた)。
     *
     * 「診断が終端に達した時点(FinalizeAnalysisJob)で、改善提案の
     * dispatchIfReady()の最後の判定が既に終わっている」と言えるかを
     * コードで確認した: JobType::GenerateBrandWheelAnalysisはwebsiteFanOutTypes()
     * の必須項目(skip_brand_wheel=trueでない限りexcludeSkippedJobTypes()で
     * 除外されない、JobType::websiteLevelTypes()参照)であり、
     * AnalysisPipeline::maybeFinalizeWebsiteAnalysis()は必須の全JobTypeが
     * 終端に達するまでWebsiteAnalysisを終端にしない。maybeFinalizeAnalysis()は
     * 全WebsiteAnalysisが終端に達するまでFinalizeAnalysisJobを起動しない。
     * したがって、FinalizeAnalysisJobが動く時点で、自社・競合すべての
     * GenerateBrandWheelAnalysisJob::completeAsSuccess/Failed()(cascadeProgress()
     * →BrandWheelImprovementSuggestionDispatcher::dispatchIfReady()を同一
     * プロセス内で同期的に呼ぶ)は既に完了している ―― dispatchIfReady()の
     * 「生成するかどうか」の判定自体はこの時点で確実に下されている
     * (行が作られ生成Jobがdispatch済みか、生成不要と判定済みかのいずれか)。
     * ただし判定が下された「あと」の実際のAI生成(数秒〜)が終わっている
     * ことまでは保証されないため、下記isBrandWheelImprovementSuggestionSettled()
     * で「行が無い(生成不要と判定済み)」か「行があり終端状態」かを見て
     * 判断する ―― 「行が無い」を無条件に「もう作られない」と読み替える
     * (依頼AJ-1が直したのと同じ誤り)ことはしない。
     */
    public function isBrandWheelImprovementSuggestionSettled(Analysis $analysis): bool
    {
        if ($analysis->skip_brand_wheel === true) {
            return true;
        }

        $websiteAnalysisCount = WebsiteAnalysis::query()->where('analysis_id', $analysis->id)->count();
        $brandWheelResultDistinctCount = BrandWheelAnalysisResult::query()
            ->where('analysis_id', $analysis->id)
            ->distinct()
            ->count('website_analysis_id');

        if ($brandWheelResultDistinctCount < $websiteAnalysisCount) {
            // 上記の根拠により、FinalizeAnalysisJob到達時点では通常
            // 起こらないはずだが、万一のズレに対する安全側の判断として
            // 「未確定」扱いにする(誤って早すぎる判定をしない)。
            return false;
        }

        $suggestion = BrandWheelImprovementSuggestion::query()->where('analysis_id', $analysis->id)->first();

        if ($suggestion === null) {
            // dispatchIfReady()の判定は上記の理由で既に完了している。行が
            // 無い=生成不要と判定済み(自社が読み取れない等)であり、以後も
            // 作られない。
            return true;
        }

        return in_array($suggestion->status, ['success', 'error'], true);
    }

    /**
     * 呼び出し側で既にreportable=trueと判定済みであることを前提とする
     * (このメソッド自体はeligibilityを再判定しない)。`dispatchIfReportable()`
     * と`LeadAnalysisController::maybeDispatchReportGeneration()`の
     * reportable=true分岐の両方から呼ばれる、Report行作成
     * (unique制約違反時は既にどちらかが先に作成したものとみなし無視する)+
     * GenerateLeadReportJob起動の唯一の実装。
     *
     * 依頼AM-1(2026-08-28): $forceWithoutImprovementSuggestion=falseかつ
     * 改善提案がまだ確定していない場合は、Report行を作らず
     * WaitForBrandWheelImprovementSuggestionJobへ委ねる ―― 呼び出し元が
     * dispatchIfReportable()経由(FinalizeAnalysisJob)・
     * LeadAnalysisController::maybeDispatchReportGeneration()経由
     * (リードのポーリング)のどちらであっても、この唯一の実装を通る
     * ため同じガードがかかる(どちらかが先にReport行を作れば、もう
     * 一方はexists()チェックで素通りする既存の仕組みは無改修)。
     */
    public function createPendingReportsAndDispatch(Analysis $analysis, bool $forceWithoutImprovementSuggestion = false): void
    {
        if (! $forceWithoutImprovementSuggestion && ! $this->isBrandWheelImprovementSuggestionSettled($analysis)) {
            WaitForBrandWheelImprovementSuggestionJob::dispatch($analysis->id)->onQueue('reports');

            return;
        }

        try {
            foreach ([ReportFormat::Docx, ReportFormat::Pdf] as $format) {
                Report::query()->create([
                    'analysis_id' => $analysis->id,
                    'format' => $format->value,
                    'storage_path' => '',
                    'status' => ReportGenerationStatus::Pending->value,
                ]);
            }
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23505') {
                Log::error('Failed to create lead report rows', [
                    'analysis_id' => $analysis->id,
                    'sqlstate' => $e->getCode(),
                    'exception_message' => $e->getMessage(),
                ]);
            }

            return;
        }

        GenerateLeadReportJob::dispatch($analysis->id)->onQueue('reports');
    }
}
