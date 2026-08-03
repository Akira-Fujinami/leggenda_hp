<?php

namespace App\Services\BrandWheel;

use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\WebsiteAnalysis;
use App\Services\Lead\LeadNotificationService;

/**
 * 「2通目(ブランド・ホイール分析結果)メール」を送ってよいかを判定し、
 * 必要なら送る。2026-08-03の設計変更: ブランド・ホイール生成は診断実行時に
 * 自社・競合の両方についてdispatchされるため、生成完了と「相談したい」の
 * 意思表示(consultation_requested_at)は別々のタイミングで起こりうる。
 *
 * 送信のきっかけは2箇所(どちらが先に起きても送られる、二重送信は
 * staff_notified_at/lead_notified_atの冪等更新が防ぐ):
 * 1. GenerateBrandWheelAnalysisJobの終端(成功/insufficient_input)から
 *    ―― 生成完了時点で既に相談済みなら、その場で送る。
 * 2. LeadAnalysisController::requestConsultation()から ―― 相談リクエスト
 *    受付時点で既に生成が完了していれば、その場で送る。
 *
 * 対象は自社(is_primary)サイトの結果のみ ―― 2通目メールは元々
 * 自社サイト1件分の評価を送るものであり、この設計は変更しない。
 */
class BrandWheelCompletionNotifier
{
    /**
     * @var list<string>
     */
    private const NOTIFIABLE_STATUSES = ['success', 'insufficient_input'];

    public function __construct(
        private readonly LeadNotificationService $notifications,
    ) {}

    public function notifyIfReady(BrandWheelAnalysisResult $record): void
    {
        if (! in_array($record->status, self::NOTIFIABLE_STATUSES, true)) {
            // 'error'/'pending'/'running'は対象外(既存方針を踏襲 ―― 純粋な
            // 処理エラーを積極的にリードへ通知することはしない)。
            return;
        }

        $websiteAnalysis = WebsiteAnalysis::find($record->website_analysis_id);
        $websiteAnalysis?->loadMissing('website');

        if ($websiteAnalysis === null || ! (bool) ($websiteAnalysis->website?->is_primary)) {
            return;
        }

        $analysis = Analysis::find($record->analysis_id);
        $analysis?->loadMissing('project.leadSession');
        $leadSession = $analysis?->project?->leadSession;

        if ($leadSession === null || $leadSession->consultation_requested_at === null) {
            // まだ「相談したい」の意思表示が無い。生成完了だけでは送らない
            // ―― 相談ボタン側が押された時点で再度この判定を通る。
            return;
        }

        $targetUrl = (string) ($websiteAnalysis->website?->normalized_url ?? '');

        if ($record->staff_notified_at === null) {
            $staffSent = $this->notifications->notifyBrandWheelAnalysisCompleted(
                $record,
                $leadSession->company_name ?? '(不明)',
                $leadSession->contact_name ?? '(不明)',
                $targetUrl,
            );

            if ($staffSent) {
                $record->update(['staff_notified_at' => now()]);
            }
        }

        if ($record->lead_notified_at === null) {
            $leadSent = $this->notifications->notifyBrandWheelAnalysisCompletedToLead(
                $record,
                $leadSession->email,
                $targetUrl,
            );

            if ($leadSent) {
                $record->update(['lead_notified_at' => now()]);
            }
        }
    }
}
