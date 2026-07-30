<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * ブランド・ホイール(6軸)分析結果を社内スタッフへ送る2通目メール。
 * 社内向けのみ ―― リード企業には一切送信しない(呼び出し元
 * LeadNotificationService::notifyBrandWheelAnalysisCompleted()が
 * 常にconfig('lead.notification_to')の社内共有メールボックス宛てに送る)。
 *
 * ヘキサゴン画像はCIDインライン埋め込み1パートのみ(通常添付は併用しない
 * ―― 2026-07-30の指摘: CIDインライン自体が既にMIME上の添付パートであり、
 * 二重に添付するとメールサイズが倍になり、企業のメールゲートウェイでの
 * 到達性を不要に悪化させるため)。画像はメール本文の補助情報であり、
 * 画像が読み込まれなくてもBladeビュー内のHTML表だけで内容が伝わる
 * ように作ること(画像をブロックする受信環境が多いため)。
 */
class BrandWheelAnalysisCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data  Bladeビューへそのまま渡すデータ
     */
    public function __construct(
        public readonly array $data,
        public readonly ?string $pngBytes,
    ) {}

    public function build(): self
    {
        $subject = $this->data['insufficientInput']
            ? "【ブランド・ホイール】評価不可 - {$this->data['companyName']}様"
            : "【ブランド・ホイール】診断結果 - {$this->data['companyName']}様";

        $mail = $this->subject($subject)
            ->view('emails.brand-wheel.completed')
            ->with($this->data);

        if ($this->pngBytes !== null) {
            $mail->with('pngBytes', $this->pngBytes);
        }

        return $mail;
    }
}
