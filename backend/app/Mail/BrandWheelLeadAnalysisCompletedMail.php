<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * ブランド・ホイール(6軸)分析結果をリード企業自身へ送るメール。
 * 社内スタッフ向け(BrandWheelAnalysisCompletedMail)とは目的・送信条件・内容が
 * すべて異なる別のメールであり、Bladeビューも物理的に別ファイルにする
 * (同一ファイル内の条件分岐にすると、社内向けの記述を編集した際に
 * 社外向けへ意図せず漏れる経路ができるため、2026-07-30の指摘)。
 *
 * このMailableはBrandWheelLeadEmailContentBuilder::canSend()がtrueの場合
 * にのみ構築されること(呼び出し側=LeadNotificationServiceの責務)。
 * 品質所見(quality_dimension_notes)・スコア・5段階・パーセンテージ・
 * N/6の分数形式は一切含まない(そもそもこのMailableのデータ構造に
 * それらのフィールドが存在しない)。
 */
class BrandWheelLeadAnalysisCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data  Bladeビューへそのまま渡すデータ
     */
    public function __construct(
        public readonly array $data,
    ) {}

    public function build(): self
    {
        return $this->subject('サイト診断のご相談ありがとうございます')
            ->view('emails.brand-wheel.lead-completed')
            ->with($this->data);
    }
}
