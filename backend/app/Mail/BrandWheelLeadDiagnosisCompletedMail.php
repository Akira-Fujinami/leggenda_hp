<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
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
 * という既存の安全設計をそのまま引き継ぐため。結果画面/レポートダウンロード
 * へのリンクは含めない ―― 生トークンはlead_sessions(token_hashのみ保存、
 * 依頼Y-3)からは復元できず、このメールの送信元(GenerateLeadReportJobの
 * 終端、バックグラウンドJob)にはリクエストコンテキストが無いため
 * 生トークンを持たない(依頼AS-1で確認)。
 */
class BrandWheelLeadDiagnosisCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data  BrandWheelLeadEmailContentBuilder::build()の出力をそのまま渡す
     */
    public function __construct(
        public readonly array $data,
    ) {}

    public function build(): self
    {
        return $this->subject('採用サイト診断が完了しました')
            ->view('emails.brand-wheel.lead-diagnosis-completed')
            ->with($this->data);
    }
}
