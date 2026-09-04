<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

/**
 * 依頼AS-2(2026-09-03): 「診断が完了しました」メール。相談リクエスト
 * (consultation_requested_at)の有無にかかわらず、レポート(Word/PDF)生成が
 * 完了した時点で送る(LeadDiagnosisCompletedNotifier参照)。
 *
 * 既存のBrandWheelLeadAnalysisCompletedMail(件名「サイト診断のご相談
 * ありがとうございます」)とは別のMailable ―― あちらは相談リクエストへの
 * 返信として書かれた文面(「ご相談のお申し込みをいただき」「担当者より
 * あらためてご連絡させていただきます」)であり、相談していない相手に
 * 送ると事実と異なるメールになる(依頼AS-0/AS-1で確認)。このMailableは
 * 相談の有無を前提にしない中立的な文面にする。
 *
 * ビューデータの組み立てはBrandWheelLeadEmailContentBuilderをそのまま
 * 再利用する(canSend()による送信可否判定も含め無改修) ―― 「品質所見の
 * 生の記述やスコア・分数形式は含めない」「AIに新しい文章を書かせない」
 * という既存の安全設計をそのまま引き継ぐため。結果画面へのリンクは
 * 含めない ―― 生トークンはlead_sessions(token_hashのみ保存、依頼Y-3)
 * からは復元できず、このメールの送信元(GenerateLeadReportJobの終端、
 * バックグラウンドJob)にはリクエストコンテキストが無いため生トークンを
 * 持たない(依頼AS-1で確認)。
 *
 * 依頼AW-1/AW-2(2026-09-04): 結果画面へ戻れない代わりに、診断レポート
 * (PDF)を添付する(取れない場合はnull、本文だけで送る ――
 * LeadNotificationService::resolvePdfAttachment()参照)。相談の申し込みは
 * 「結果画面の『相談する』ボタン」ではなく「本メールへの返信」で受け付ける
 * よう案内するため、返信先を社内共有メールボックス(config('lead.
 * notification_to'))に向ける ―― 未設定の場合は返信先を変更しない
 * (Fromのまま、既存の挙動を壊さない)。
 */
class BrandWheelLeadDiagnosisCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data  BrandWheelLeadEmailContentBuilder::build()の出力をそのまま渡す
     */
    public function __construct(
        public readonly array $data,
        public readonly ?Attachment $pdfAttachment = null,
    ) {}

    public function build(): self
    {
        $mail = $this->subject('採用サイト診断が完了しました')
            ->view('emails.brand-wheel.lead-diagnosis-completed')
            ->with($this->data + ['hasPdfAttachment' => $this->pdfAttachment !== null]);

        $replyTo = config('lead.notification_to');
        if (is_string($replyTo) && $replyTo !== '') {
            $mail = $mail->replyTo($replyTo);
        }

        if ($this->pdfAttachment !== null) {
            $mail = $mail->attach($this->pdfAttachment);
        }

        return $mail;
    }
}
