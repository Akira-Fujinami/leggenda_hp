<?php

namespace Tests\Unit\Mail;

use App\Mail\BrandWheelAnalysisCompletedMail;
use App\Models\BrandWheelAnalysisResult;
use App\Services\BrandWheel\BrandWheelEmailContentBuilder;
use App\Services\BrandWheel\BrandWheelHexagonRenderer;
use App\Services\BrandWheel\BrandWheelHexagonSvgBuilder;
use Tests\TestCase;

class BrandWheelAnalysisCompletedMailTest extends TestCase
{
    private function makeSuccessResult(): BrandWheelAnalysisResult
    {
        return new BrandWheelAnalysisResult([
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'state' => 'read', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => '地域社会に貢献するという理念'],
                ]],
                ['axis_key' => 'asset', 'state' => 'partial', 'matched_sub_elements' => []],
                ['axis_key' => 'personality', 'state' => 'unread', 'matched_sub_elements' => []],
                ['axis_key' => 'relationship', 'state' => 'unread', 'matched_sub_elements' => []],
                ['axis_key' => 'emotional_benefit', 'state' => 'unread', 'matched_sub_elements' => []],
                ['axis_key' => 'financial_benefit', 'state' => 'unread', 'matched_sub_elements' => []],
            ],
            'axis_state_counts' => ['read' => 1, 'partial' => 1, 'unread' => 4],
            'core_value_readable' => true,
            'core_value_evidence' => '仕事の舞台裏にこそ価値がある',
            'quality_dimension_notes' => ['consistency' => '6軸を通じて一貫したメッセージが見られます。'],
            'cautions' => ['この分析はAIによる参考情報です。'],
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ]);
    }

    /**
     * 画像(<img>タグ)を機械的に除去したHTMLに、6軸それぞれの軸名・判定・
     * 根拠抜粋・免責文言がすべて残っていることを検証する。「画像が読み込まれ
     * なくても本文の表だけで内容が伝わる」という要件そのものの回帰テスト
     * (2026-07-30の指摘)。
     */
    public function test_email_content_is_fully_comprehensible_with_all_img_tags_stripped(): void
    {
        $result = $this->makeSuccessResult();
        $data = app(BrandWheelEmailContentBuilder::class)->build($result, '株式会社サンプル', '山田太郎', 'https://example.com');
        $svg = app(BrandWheelHexagonSvgBuilder::class)->build($result);
        $png = app(BrandWheelHexagonRenderer::class)->renderPng($svg);

        $mail = new BrandWheelAnalysisCompletedMail($data, $png);
        $html = $mail->render();

        $htmlWithoutImages = preg_replace('/<img[^>]*>/i', '', $html);

        foreach (['活動的魅力', '資産的魅力', '経営スタイル', '就業環境', '情緒的便益', '金銭的便益'] as $nameJa) {
            $this->assertStringContainsString($nameJa, $htmlWithoutImages);
        }
        foreach (['読み取れました', '一部読み取れました', '読み取れませんでした'] as $stateLabel) {
            $this->assertStringContainsString($stateLabel, $htmlWithoutImages);
        }
        $this->assertStringContainsString('地域社会に貢献するという理念', $htmlWithoutImages);
        $this->assertStringContainsString('仕事の舞台裏にこそ価値がある', $htmlWithoutImages);
        $this->assertStringContainsString('一貫したメッセージ', $htmlWithoutImages);
        $this->assertStringContainsString('本内容はAIによる参考情報です', $htmlWithoutImages);
        // 分数表示('1/6'等)は使わない。
        $this->assertStringNotContainsString('1/6', $htmlWithoutImages);
        $this->assertStringContainsString('読み取れた1軸／一部読み取れた1軸／読み取れなかった4軸', $htmlWithoutImages);
    }

    public function test_insufficient_input_email_omits_hexagon_and_axis_judgments(): void
    {
        $result = new BrandWheelAnalysisResult([
            'status' => 'insufficient_input',
            'source_pages' => ['recruit_page' => 'absent', 'home_page' => 'read'],
        ]);
        $data = app(BrandWheelEmailContentBuilder::class)->build($result, '株式会社サンプル', '山田太郎', 'https://example.com');

        $mail = new BrandWheelAnalysisCompletedMail($data, null);
        $html = $mail->render();

        $this->assertStringContainsString('評価不可', $html);
        $this->assertStringNotContainsString('brand-wheel-hexagon.png', $html);
        foreach (['活動的魅力', '資産的魅力', '経営スタイル'] as $nameJa) {
            $this->assertStringNotContainsString($nameJa, $html);
        }
    }

    /**
     * ヘキサゴン画像はCIDインライン1パートのみで、通常添付を併用しない
     * (2026-07-30の指摘 ―― 二重添付はメールサイズを倍にし、到達性を悪化させる)。
     *
     * Mail::fake()は実際のビュー描画($message->embedData()の呼び出し)前に
     * 送信をインターセプトしてしまうため、MIME構造の検証には使えない。
     * 実際にレンダリングしたHTML内の<img>タグ数と、Mailable/Blade側の
     * ソースコードにattach()呼び出しが存在しないことの両方で確認する。
     */
    public function test_hexagon_image_is_embedded_as_a_single_inline_part_not_double_attached(): void
    {
        $result = $this->makeSuccessResult();
        $data = app(BrandWheelEmailContentBuilder::class)->build($result, '株式会社サンプル', '山田太郎', 'https://example.com');
        $svg = app(BrandWheelHexagonSvgBuilder::class)->build($result);
        $png = app(BrandWheelHexagonRenderer::class)->renderPng($svg);
        $this->assertNotNull($png);

        $mail = new BrandWheelAnalysisCompletedMail($data, $png);
        $html = $mail->render();

        $this->assertSame(1, substr_count($html, '<img'));

        $mailableSource = file_get_contents(app_path('Mail/BrandWheelAnalysisCompletedMail.php'));
        $viewSource = file_get_contents(resource_path('views/emails/brand-wheel/completed.blade.php'));
        $this->assertSame(1, substr_count($viewSource, 'embedData'));
        $this->assertStringNotContainsString('->attach(', $mailableSource);
        $this->assertStringNotContainsString('->attach(', $viewSource);
    }

    public function test_alt_text_is_present_on_the_image_tag(): void
    {
        $result = $this->makeSuccessResult();
        $data = app(BrandWheelEmailContentBuilder::class)->build($result, '株式会社サンプル', '山田太郎', 'https://example.com');
        $svg = app(BrandWheelHexagonSvgBuilder::class)->build($result);
        $png = app(BrandWheelHexagonRenderer::class)->renderPng($svg);

        $mail = new BrandWheelAnalysisCompletedMail($data, $png);
        $html = $mail->render();

        $this->assertStringContainsString('alt="'.$data['altText'].'"', $html);
    }
}
