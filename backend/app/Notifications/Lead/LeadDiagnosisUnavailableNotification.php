<?php

namespace App\Notifications\Lead;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 自社サイトの診断結果を提供できなかった(ブランド・ホイールが
 * error/insufficient_input/matched=0で終端し、レポートを意図的に生成
 * しなかった)ことをリード本人へ伝える(2026-08-24追加、依頼者確定文言)。
 *
 * サイトを責める表現・具体的な技術的理由は書かない。診断回数を消費して
 * いないことを明記し、別のURLでの再挑戦とお問い合わせ導線を残す
 * (LeadAnalysisController::maybeDispatchReportGeneration()から、
 * BrandWheelReportEligibility::isReportable()がfalseになった時点で
 * 1診断につき1回送る)。
 */
class LeadDiagnosisUnavailableNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $leadSessionId,
        private readonly string $companyName,
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
            ->subject('診断結果のご案内')
            ->greeting("{$this->companyName} 様")
            ->line('今回はご用意できる診断結果がありませんでした。')
            ->line('恐れ入りますが、別のURLで再度お試しいただけますでしょうか。採用サイトのトップページ、またはコーポレートサイトの採用情報ページをおすすめします。')
            ->line('なお、今回のご利用は回数に含まれておりません。')
            ->action('もう一度お試しになる', $this->resultsUrl)
            ->line('ご不明点やご相談がございましたら、お問い合わせフォームよりお気軽にご連絡ください。');
    }

    /**
     * キューが最終的に失敗を確定した時点でのみ呼ばれる。ログにはメール本文
     * (会社名等)を残さず、成否の判断に必要なlead_session_idのみを記録する。
     */
    public function failed(Throwable $exception): void
    {
        report($exception);

        Log::warning('LeadDiagnosisUnavailableNotification failed to send', [
            'lead_session_id' => $this->leadSessionId,
        ]);
    }
}
