<?php

namespace App\Notifications\Lead;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * リードが無料診断を開始したことを社内共有メールボックスへ通知する。
 * notifications専用キューで非同期に送るため、送信の成否は
 * StartAnalysisJob等の分析パイプライン・リードへのHTTPレスポンスに
 * 一切影響しない。
 *
 * 本文の「診断結果を開く」リンクはリード自身のワンタイムトークンを
 * 再利用する(2026-07-28までの合意: 宛先が共有メールボックスであること・
 * 転送注意の一文を必ず添えることが条件)。
 */
class LeadAnalysisStartedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $leadSessionId,
        private readonly string $companyName,
        private readonly string $contactName,
        private readonly string $email,
        private readonly int $analysisId,
        private readonly string $resultsUrl,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("【無料診断開始のお知らせ】{$this->companyName}様")
            ->greeting('無料診断が開始されました')
            ->line("会社名: {$this->companyName}")
            ->line("ご担当者名: {$this->contactName}")
            ->line("メールアドレス: {$this->email}")
            ->line("分析ID: {$this->analysisId}")
            ->action('診断結果を確認する', $this->resultsUrl)
            ->line('このリンクには診断結果を開く権限が含まれます。転送しないでください。');
    }

    /**
     * キューが最終的に失敗を確定した時点でのみ呼ばれる。ログにはメール本文
     * (会社名・氏名・メールアドレス等)を一切残さず、成否の判断に必要な
     * lead_session_idのみを記録する。
     */
    public function failed(Throwable $exception): void
    {
        report($exception);

        Log::warning('LeadAnalysisStartedNotification failed to send', [
            'lead_session_id' => $this->leadSessionId,
        ]);
    }
}
