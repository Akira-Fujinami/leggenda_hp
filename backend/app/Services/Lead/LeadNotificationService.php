<?php

namespace App\Services\Lead;

use App\Models\LeadSession;
use App\Notifications\Lead\LeadAnalysisStartedNotification;
use App\Notifications\Lead\LeadConsultationRequestedNotification;
use Illuminate\Support\Facades\Log;
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
