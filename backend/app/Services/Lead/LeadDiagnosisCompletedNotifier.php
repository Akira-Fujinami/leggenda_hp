<?php

namespace App\Services\Lead;

use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\WebsiteAnalysis;
use Throwable;

/**
 * 依頼AS-2(2026-09-03): 「診断が完了しました」メール(相談リクエストの
 * 有無にかかわらず送る)を送ってよいかを判定し、必要なら送る。
 *
 * 送信のきっかけは1箇所のみ: GenerateLeadReportJobがWord・PDF両方の生成に
 * 成功した終端(依頼AS-1の調査結果 ―― レポートが実際に完成している時点。
 * 改善提案が上限到達で改善提案なしのまま強行完成したケース(依頼AM-1)も、
 * このJob自体は区別なく成功するため、この通知も区別なく発火する)。
 *
 * 既存のBrandWheelCompletionNotifier(社内向け・相談リクエスト起点のリード
 * 向け、依頼AS-0/AS-1で調査した既存の仕組み)とは完全に独立 ―― トリガー・
 * 二重送信防止の列(Analysis.lead_diagnosis_completed_notified_at、
 * BrandWheelAnalysisResult.lead_notified_atとは別物)・文面(BrandWheelLead
 * DiagnosisCompletedMail、相談を前提にしない中立的な文面)のいずれも別。
 * 依頼者指定: 既存の仕組み(社内向け・相談リクエスト起点のリード向け)は
 * この依頼で一切変更しない。
 */
class LeadDiagnosisCompletedNotifier
{
    public function __construct(
        private readonly LeadNotificationService $notifications,
    ) {}

    public function notifyIfReady(Analysis $analysis): void
    {
        $analysis->loadMissing('project.leadSession');
        $leadSession = $analysis->project?->leadSession;

        if ($leadSession === null) {
            // 内部向け分析(lead_session_idを持たないProject)からは
            // GenerateLeadReportJob自体がdispatchされない想定だが、
            // 二重の安全弁として何もしない。
            return;
        }

        $selfWebsiteAnalysis = WebsiteAnalysis::query()
            ->where('analysis_id', $analysis->id)
            ->whereHas('website', fn ($query) => $query->where('is_primary', true))
            ->with('website')
            ->first();

        if ($selfWebsiteAnalysis === null) {
            return;
        }

        $result = BrandWheelAnalysisResult::query()
            ->where('website_analysis_id', $selfWebsiteAnalysis->id)
            ->latest('id')
            ->first();

        if ($result === null) {
            return;
        }

        // Analysis.lead_quota_consumed_atと同じ「nullの行だけを対象にした
        // 条件付きUPDATE」で一度だけ勝者を決める(LeadSessionService::
        // recordConsultationRequested()と同じ方式)。GenerateLeadReportJobは
        // リトライ(部分的な失敗からの再実行)で複数回handle()が完了する
        // ことがあるため、この通知が二重に送られないようにする。
        $claimed = Analysis::query()
            ->whereKey($analysis->id)
            ->whereNull('lead_diagnosis_completed_notified_at')
            ->update(['lead_diagnosis_completed_notified_at' => now()]);

        if ($claimed === 0) {
            // 既に送信済み(このJobのリトライによる再実行)。二重送信しない。
            return;
        }

        $targetUrl = (string) ($selfWebsiteAnalysis->website?->normalized_url ?? '');

        try {
            $sent = $this->notifications->notifyDiagnosisCompletedToLead(
                $result,
                $leadSession->email,
                $targetUrl,
            );
        } catch (Throwable $e) {
            $sent = false;
            report($e);
        }

        if (! $sent) {
            // 送信に失敗した(またはcanSend()がfalseだった)場合は、次回の
            // 呼び出しで再試行できるようマーカーを戻す。メール送信の失敗が
            // レポート生成自体の成否に影響しないことは、この関数の呼び出し元
            // (GenerateLeadReportJob)側でtry/catchにより保証する。
            Analysis::query()->whereKey($analysis->id)->update(['lead_diagnosis_completed_notified_at' => null]);
        }
    }
}
