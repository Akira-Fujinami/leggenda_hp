<?php

namespace Tests\Unit\Mail;

use App\Mail\BrandWheelLeadAnalysisCompletedMail;
use App\Models\BrandWheelAnalysisResult;
use App\Services\BrandWheel\BrandWheelLeadEmailContentBuilder;
use Tests\TestCase;

class BrandWheelLeadAnalysisCompletedMailTest extends TestCase
{
    private function makeResult(): BrandWheelAnalysisResult
    {
        return new BrandWheelAnalysisResult([
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'state' => 'read', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => '地域社会に貢献するという理念'],
                ]],
                ['axis_key' => 'asset', 'state' => 'partial', 'matched_sub_elements' => []],
            ],
            'axis_state_counts' => ['read' => 1, 'partial' => 1, 'unread' => 4],
            'quality_dimension_notes' => ['consistency' => '内輪向けの分析所見(社外秘)'],
            'cautions' => [],
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ]);
    }

    /**
     * 社外へ出るメールに対する最後の防波堤。config('brand_wheel.forbidden_phrases')
     * に列挙された語が実際のレンダリング結果に一切出現しないことを検証する
     * (2026-07-30の要件 ―― ハードコードせずconfigを参照すること)。
     */
    public function test_rendered_html_never_contains_any_configured_forbidden_phrase(): void
    {
        $result = $this->makeResult();
        $data = app(BrandWheelLeadEmailContentBuilder::class)->build($result, 'https://example.com');

        $html = (new BrandWheelLeadAnalysisCompletedMail($data))->render();

        $forbiddenPhrases = (array) config('brand_wheel.forbidden_phrases', []);
        $this->assertNotEmpty($forbiddenPhrases, 'config(brand_wheel.forbidden_phrases) is empty; the test would pass vacuously.');

        foreach ($forbiddenPhrases as $phrase) {
            $this->assertStringNotContainsString($phrase, $html, "forbidden phrase leaked into lead-facing email: {$phrase}");
        }
    }

    public function test_rendered_html_contains_the_three_mandatory_disclosures(): void
    {
        $result = $this->makeResult();
        $data = app(BrandWheelLeadEmailContentBuilder::class)->build($result, 'https://example.com');

        $html = (new BrandWheelLeadAnalysisCompletedMail($data))->render();

        $this->assertStringContainsString('実態そのものを評価したものではありません', $html);
        $this->assertStringContainsString('グループインタビュー', $html);
        $this->assertStringContainsString('サイト以外の情報も併せて構築する', $html);
        $this->assertStringContainsString('AIが生成したもの', $html);
        // 何を読んで判断したか。
        $this->assertStringContainsString('読み取りました', $html);
    }

    public function test_rendered_html_never_contains_quality_dimension_notes_or_score_like_content(): void
    {
        $result = $this->makeResult();
        $data = app(BrandWheelLeadEmailContentBuilder::class)->build($result, 'https://example.com');

        $html = (new BrandWheelLeadAnalysisCompletedMail($data))->render();

        // quality_dimension_notesの生の記述(社内向け所見)が漏れていないこと。
        $this->assertStringNotContainsString('内輪向けの分析所見', $html);

        // スコア・5段階・パーセンテージ・N/6の分数形式を使わない(数値による
        // 評価の示唆)。HTML/CSSのレイアウト属性(width="100%"等)は対象外のため、
        // タグを除去した可視テキスト部分のみを検査する。
        $visibleText = strip_tags($html);
        $this->assertDoesNotMatchRegularExpression('/\d+\s*%/', $visibleText);
        $this->assertStringNotContainsString('1/6', $visibleText);
        $this->assertMatchesRegularExpression('/読み取れた1軸／一部読み取れた1軸／読み取れなかった4軸/u', $visibleText);
    }
}
