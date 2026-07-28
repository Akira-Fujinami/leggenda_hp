<?php

namespace App\Notifications\Lead;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * リードが「もっと他社と比較したい/相談したい」ボタンを押したことを
 * 社内共有メールボックスへ通知する。初回オンボーディングフォームの内容と
 * 診断要約を含め、担当者がリンクを踏まなくても概要を把握できるようにする。
 *
 * notifications専用キューで非同期に送るため、送信の成否はリードへの
 * HTTPレスポンスに一切影響しない。
 */
class LeadConsultationRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $leadSessionId,
        private readonly string $companyName,
        private readonly string $contactName,
        private readonly string $email,
        private readonly ?string $phone,
        private readonly ?string $industry,
        private readonly ?string $employeeRange,
        private readonly int $analysisId,
        private readonly string $scoreSummary,
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
        $message = (new MailMessage)
            ->subject("【相談リクエスト】{$this->companyName}様")
            ->greeting('相談リクエストが届きました')
            ->line("会社名: {$this->companyName}")
            ->line("ご担当者名: {$this->contactName}")
            ->line("メールアドレス: {$this->email}");

        if ($this->phone !== null && $this->phone !== '') {
            $message->line("電話番号: {$this->phone}");
        }

        if ($this->industry !== null && $this->industry !== '') {
            $message->line("業種: {$this->industry}");
        }

        if ($this->employeeRange !== null && $this->employeeRange !== '') {
            $message->line("従業員規模: {$this->employeeRange}");
        }

        return $message
            ->line("分析ID: {$this->analysisId}")
            ->line("診断結果の要約: {$this->scoreSummary}")
            ->action('診断結果を確認する', $this->resultsUrl)
            ->line('このリンクには診断結果を開く権限が含まれます。転送しないでください。');
    }

    public function failed(Throwable $exception): void
    {
        report($exception);

        Log::warning('LeadConsultationRequestedNotification failed to send', [
            'lead_session_id' => $this->leadSessionId,
        ]);
    }
}
