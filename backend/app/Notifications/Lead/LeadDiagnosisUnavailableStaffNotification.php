<?php

namespace App\Notifications\Lead;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 自社サイトの診断結果を提供できなかったことを社内共有メールボックスへ
 * 通知する(2026-08-24追加)。既存のLeadAnalysisStartedNotification(1通目)と
 * 同じ設計方針: notifications専用キューで非同期に送るため、送信の成否は
 * 分析パイプライン・レポート判定に一切影響しない。
 *
 * 本文に含める範囲は1通目(LeadAnalysisStartedNotification)と同じ
 * (会社名・ご担当者名・メールアドレス・分析ID)に、営業が動くために
 * 必要な「診断できなかった理由」(人が読める日本語の要約のみ、内部の
 * status文字列・例外メッセージ・スタックトレースは含めない)を加える。
 * リードは結果を見ていないため「相談したい」ボタンを押す機会が無く、
 * 既存の2通目通知(BrandWheelCompletionNotifier、'error'を除外・相談
 * リクエスト待ち)には構造的に乗らない ―― この通知は待たずに即送る。
 */
class LeadDiagnosisUnavailableStaffNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $leadSessionId,
        private readonly string $companyName,
        private readonly string $contactName,
        private readonly string $email,
        private readonly int $analysisId,
        private readonly string $reasonSummary,
        private readonly string $adminUrl,
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
            ->subject("【要フォロー】{$this->companyName}様 診断結果をご用意できませんでした")
            ->greeting('診断結果をご用意できなかったリードがあります')
            ->line("会社名: {$this->companyName}")
            ->line("ご担当者名: {$this->contactName}")
            ->line("メールアドレス: {$this->email}")
            ->line("分析ID: {$this->analysisId}")
            ->line("状況: {$this->reasonSummary}")
            ->line('診断回数は消費されていないため、リードは別のURLで再挑戦できます。営業側からのフォローが必要かご判断ください。')
            ->action('管理画面で確認する', $this->adminUrl);
    }

    /**
     * キューが最終的に失敗を確定した時点でのみ呼ばれる。ログにはメール本文
     * (会社名・氏名・メールアドレス等)を一切残さず、成否の判断に必要な
     * lead_session_idのみを記録する。
     */
    public function failed(Throwable $exception): void
    {
        report($exception);

        Log::warning('LeadDiagnosisUnavailableStaffNotification failed to send', [
            'lead_session_id' => $this->leadSessionId,
        ]);
    }
}
