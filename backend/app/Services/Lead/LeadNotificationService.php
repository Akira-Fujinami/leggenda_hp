<?php

namespace App\Services\Lead;

use App\Mail\BrandWheelAnalysisCompletedMail;
use App\Mail\BrandWheelLeadAnalysisCompletedMail;
use App\Models\BrandWheelAnalysisResult;
use App\Models\LeadSession;
use App\Notifications\Lead\LeadAnalysisStartedNotification;
use App\Notifications\Lead\LeadConsultationRequestedNotification;
use App\Notifications\Lead\LeadDiagnosisUnavailableNotification;
use App\Notifications\Lead\LeadDiagnosisUnavailableStaffNotification;
use App\Services\BrandWheel\BrandWheelEmailContentBuilder;
use App\Services\BrandWheel\BrandWheelHexagonRenderer;
use App\Services\BrandWheel\BrandWheelHexagonSvgBuilder;
use App\Services\BrandWheel\BrandWheelLeadEmailContentBuilder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * 診断開始・相談リクエストの社内通知を送る唯一の入口。
 *
 * 宛先(config('lead.notification_to'))が未設定の場合は、実装は完成して
 * いても実配信は行われない ―― その場合は警告ログのみを残し、例外は
 * 投げない(呼び出し側=Controllerは常に例外を気にせず呼べる)。
 * 通知自体はキュー経由(notificationsキュー)のため、dispatch自体が
 * リードへのHTTPレスポンスを遅延・失敗させることもない。
 */
class LeadNotificationService
{
    public function notifyAnalysisStarted(LeadSession $leadSession, int $analysisId, string $rawToken): void
    {
        $this->dispatch($leadSession, 'analysis_started', function (string $recipient) use ($leadSession, $analysisId, $rawToken) {
            Notification::route('mail', $recipient)->notify(new LeadAnalysisStartedNotification(
                leadSessionId: $leadSession->id,
                companyName: $leadSession->company_name,
                contactName: $leadSession->contact_name,
                email: $leadSession->email,
                analysisId: $analysisId,
                resultsUrl: $this->resultsUrl($rawToken),
            ));
        });
    }

    /**
     * 2026-08-24追加: 自社サイトの診断結果を提供できなかったことをリード
     * 本人へ伝える(LeadAnalysisController::maybeDispatchReportGeneration()
     * から、BrandWheelReportEligibility::isReportable()がfalseになった
     * 時点で1診断につき1回呼ぶ)。
     */
    public function notifyDiagnosisUnavailableToLead(LeadSession $leadSession, string $rawToken): void
    {
        Notification::route('mail', $leadSession->email)->notify(new LeadDiagnosisUnavailableNotification(
            leadSessionId: $leadSession->id,
            companyName: $leadSession->company_name,
            resultsUrl: $this->resultsUrl($rawToken),
        ));
    }

    /**
     * 2026-08-24追加: 自社サイトの診断結果を提供できなかったことを社内
     * 共有メールボックスへ通知する。config('lead.notify_staff_on_diagnosis_
     * unavailable')で無効化できる(既定true)。$reasonSummaryは人が読める
     * 日本語の要約のみ(内部のstatus文字列・例外メッセージは渡さないこと)。
     */
    public function notifyDiagnosisUnavailableToStaff(
        LeadSession $leadSession,
        int $analysisId,
        string $reasonSummary,
        string $adminUrl,
    ): void {
        if (! (bool) config('lead.notify_staff_on_diagnosis_unavailable', true)) {
            return;
        }

        $this->dispatch($leadSession, 'diagnosis_unavailable', function (string $recipient) use ($leadSession, $analysisId, $reasonSummary, $adminUrl) {
            Notification::route('mail', $recipient)->notify(new LeadDiagnosisUnavailableStaffNotification(
                leadSessionId: $leadSession->id,
                companyName: $leadSession->company_name,
                contactName: $leadSession->contact_name,
                email: $leadSession->email,
                analysisId: $analysisId,
                reasonSummary: $reasonSummary,
                adminUrl: $adminUrl,
            ));
        });
    }

    public function notifyConsultationRequested(LeadSession $leadSession, int $analysisId, string $rawToken, string $scoreSummary): void
    {
        $this->dispatch($leadSession, 'consultation_requested', function (string $recipient) use ($leadSession, $analysisId, $rawToken, $scoreSummary) {
            Notification::route('mail', $recipient)->notify(new LeadConsultationRequestedNotification(
                leadSessionId: $leadSession->id,
                companyName: $leadSession->company_name,
                contactName: $leadSession->contact_name,
                email: $leadSession->email,
                phone: $leadSession->phone,
                industry: $leadSession->industry,
                employeeRange: $leadSession->employee_range,
                analysisId: $analysisId,
                scoreSummary: $scoreSummary,
                resultsUrl: $this->resultsUrl($rawToken),
            ));
        });
    }

    /**
     * ブランド・ホイール(6軸)分析結果を社内スタッフへ送る2通目メール。
     * 1通目(notifyConsultationRequested)とは異なり、キュー経由の非同期通知
     * ではなく同期送信にする ―― 呼び出し元(GenerateBrandWheelAnalysisJob)が
     * 既にaiキュー上のJobとして実行中であり、ここでさらにnotificationsキューへ
     * 積んでも二重に待たせるだけで利点が無いため。
     *
     * 送信の成否をboolで返す ―― 呼び出し元がstaff_notified_atを更新して
     * よいかどうかの判断に使う(失敗時はnullのままにして再送可能にする、
     * 2026-07-30の指摘)。
     */
    public function notifyBrandWheelAnalysisCompleted(
        BrandWheelAnalysisResult $result,
        string $companyName,
        string $contactName,
        string $targetUrl,
    ): bool {
        $recipient = config('lead.notification_to');

        if (! is_string($recipient) || $recipient === '') {
            Log::warning('Lead notification skipped: LEAD_NOTIFICATION_TO is not configured', [
                'event' => 'brand_wheel_analysis_completed',
                'brand_wheel_analysis_result_id' => $result->id,
            ]);

            return false;
        }

        try {
            $data = app(BrandWheelEmailContentBuilder::class)->build($result, $companyName, $contactName, $targetUrl);

            $png = null;
            if (! $data['insufficientInput']) {
                $svg = app(BrandWheelHexagonSvgBuilder::class)->build($result);
                $png = app(BrandWheelHexagonRenderer::class)->renderPng($svg);
            }

            Mail::to($recipient)->send(new BrandWheelAnalysisCompletedMail($data, $png));

            return true;
        } catch (Throwable $e) {
            report($e);

            Log::warning('Brand wheel analysis completed notification failed to send', [
                'brand_wheel_analysis_result_id' => $result->id,
            ]);

            return false;
        }
    }

    /**
     * ブランド・ホイール(6軸)分析結果をリード企業自身へ送るメール。
     * 社内スタッフ向け(notifyBrandWheelAnalysisCompleted)とは受信者・送信条件・
     * 内容がすべて独立しており、片方の成否がもう片方に影響しない
     * (staff_notified_at/lead_notified_atを別々に持つ設計、2026-07-30の指摘)。
     *
     * BrandWheelLeadEmailContentBuilder::canSend()をこのメソッド自身の中でも
     * 再確認する ―― 「6軸すべて読み取れませんでした」等の内容を社外へ送ることは
     * いかなる場合もしない、という絶対のルールを、呼び出し側の判断だけに
     * 委ねず境界そのもので強制するため。
     */
    public function notifyBrandWheelAnalysisCompletedToLead(
        BrandWheelAnalysisResult $result,
        ?string $leadEmail,
        string $targetUrl,
    ): bool {
        $contentBuilder = app(BrandWheelLeadEmailContentBuilder::class);

        if (! $contentBuilder->canSend($result)) {
            Log::info('Brand wheel lead notification skipped: not eligible to send', [
                'brand_wheel_analysis_result_id' => $result->id,
                'reason' => $contentBuilder->blockedReason($result),
            ]);

            return false;
        }

        if ($leadEmail === null || $leadEmail === '') {
            Log::warning('Brand wheel lead notification skipped: no lead email address available', [
                'brand_wheel_analysis_result_id' => $result->id,
            ]);

            return false;
        }

        try {
            $data = $contentBuilder->build($result, $targetUrl);

            Mail::to($leadEmail)->send(new BrandWheelLeadAnalysisCompletedMail($data));

            return true;
        } catch (Throwable $e) {
            report($e);

            Log::warning('Brand wheel lead notification failed to send', [
                'brand_wheel_analysis_result_id' => $result->id,
            ]);

            return false;
        }
    }

    private function resultsUrl(string $rawToken): string
    {
        $frontendUrl = rtrim((string) config('cors.frontend_url'), '/');

        return "{$frontendUrl}/lead/diagnose?token={$rawToken}";
    }

    private function dispatch(LeadSession $leadSession, string $event, \Closure $send): void
    {
        $recipient = config('lead.notification_to');

        if (! is_string($recipient) || $recipient === '') {
            Log::warning('Lead notification skipped: LEAD_NOTIFICATION_TO is not configured', [
                'event' => $event,
                'lead_session_id' => $leadSession->id,
            ]);

            return;
        }

        try {
            $send($recipient);
        } catch (Throwable $e) {
            report($e);

            Log::warning('Lead notification dispatch failed', [
                'event' => $event,
                'lead_session_id' => $leadSession->id,
            ]);
        }
    }
}
