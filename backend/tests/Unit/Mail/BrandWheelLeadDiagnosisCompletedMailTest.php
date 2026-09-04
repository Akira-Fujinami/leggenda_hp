<?php

namespace Tests\Unit\Mail;

use App\Mail\BrandWheelLeadDiagnosisCompletedMail;
use App\Models\BrandWheelAnalysisResult;
use App\Services\BrandWheel\BrandWheelLeadEmailContentBuilder;
use Illuminate\Mail\Mailables\Attachment;
use Tests\TestCase;

/**
 * 依頼AW-2(2026-09-04): 本文の文言そのもの(存在してはいけない一文の除去、
 * 添付の有無で文面が成立すること、6軸の表・3つのお断りが変わらないこと)を
 * 実レンダリングで確認する。Mail::assertSent()のデータ配列比較だけでは
 * HTML文面の文言までは確認できないため、このファイルでは
 * Mailable::render()を使う。
 */
class BrandWheelLeadDiagnosisCompletedMailTest extends TestCase
{
    private function buildData(): array
    {
        $result = new BrandWheelAnalysisResult([
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'state' => 'read', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => '地域社会に貢献するという理念'],
                ]],
            ],
            'axis_state_counts' => ['read' => 1, 'partial' => 0, 'unread' => 5],
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ]);

        return app(BrandWheelLeadEmailContentBuilder::class)->build($result, 'https://example.com');
    }

    public function test_subject_is_unchanged(): void
    {
        $mail = new BrandWheelLeadDiagnosisCompletedMail($this->buildData());

        $this->assertSame('採用サイト診断が完了しました', $mail->build()->subject);
    }

    public function test_body_no_longer_claims_an_email_link_that_does_not_exist(): void
    {
        $html = (new BrandWheelLeadDiagnosisCompletedMail($this->buildData()))->render();

        $this->assertStringNotContainsString('診断時にお送りしたURL', $html);
        $this->assertStringNotContainsString('結果画面にてご確認', $html);
    }

    public function test_body_mentions_the_attachment_and_invites_a_reply_when_a_pdf_is_attached(): void
    {
        $attachment = Attachment::fromData(fn () => '%PDF-1.4 fake', '診断レポート.pdf')->withMime('application/pdf');
        $html = (new BrandWheelLeadDiagnosisCompletedMail($this->buildData(), $attachment))->render();

        $this->assertStringContainsString('添付しております', $html);
        $this->assertStringContainsString('本メールにご返信ください', $html);
    }

    public function test_body_still_reads_as_a_complete_sentence_without_an_attachment(): void
    {
        $html = (new BrandWheelLeadDiagnosisCompletedMail($this->buildData(), null))->render();

        $this->assertStringNotContainsString('添付しております', $html);
        $this->assertStringContainsString('本メールにご返信ください', $html);
    }

    public function test_the_six_axis_table_and_the_three_disclaimers_are_unchanged(): void
    {
        $html = (new BrandWheelLeadDiagnosisCompletedMail($this->buildData()))->render();

        $this->assertStringContainsString('サイトから確認できた内容', $html);
        $this->assertStringContainsString('<th style="border:1px solid #e1e0d9;">項目</th>', $html);
        $this->assertStringContainsString('<th style="border:1px solid #e1e0d9;">確認状況</th>', $html);
        $this->assertStringContainsString('<th style="border:1px solid #e1e0d9;">サイトからの抜粋</th>', $html);
        $this->assertStringContainsString('本内容はサイト上の記述から読み取れた範囲を示すものであり、貴社の実態そのものを評価したものではありません。', $html);
        $this->assertStringContainsString('採用ブランディングは本来、グループインタビュー・口コミ・内定者や辞退者へのインタビュー・説明会・SNS等、サイト以外の情報も併せて構築するものです。今回はサイトの記述のみを拝見しております。', $html);
        $this->assertStringContainsString('本内容はAIが生成したものです。', $html);
    }
}
