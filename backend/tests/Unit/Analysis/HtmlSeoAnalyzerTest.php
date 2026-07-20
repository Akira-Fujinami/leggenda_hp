<?php

namespace Tests\Unit\Analysis;

use App\Services\Analysis\HtmlSeoAnalyzer;
use Tests\TestCase;

class HtmlSeoAnalyzerTest extends TestCase
{
    private HtmlSeoAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new HtmlSeoAnalyzer;
    }

    private function sampleHtml(): string
    {
        return <<<'HTML'
        <!DOCTYPE html>
        <html lang="ja">
        <head>
            <title>サンプルページ | テスト</title>
            <meta name="description" content="これはテスト用の説明文です。">
            <link rel="canonical" href="https://example.com/">
            <meta name="robots" content="index, follow">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <link rel="icon" href="/favicon.ico">
            <meta property="og:title" content="OGタイトル">
            <meta property="og:image" content="https://example.com/og.png">
            <script type="application/ld+json">{"@type": "Organization", "name": "Example"}</script>
        </head>
        <body>
            <h1>メインタイトル</h1>
            <img src="a.png" alt="説明あり">
            <img src="b.png" alt="">
            <img src="c.png">
            <a href="/about">会社概要</a>
            <a href="https://external.com/">外部サイト</a>
            <a href="mailto:test@example.com">メール</a>
            <a href="tel:0312345678">電話</a>
            <a href="https://twitter.com/example">Twitter</a>
            <a href="/contact">お問い合わせ</a>
            <form><input type="text"><input type="submit" value="送信"><button>送信する</button></form>
            <p>これはテストの本文です。予約はこちらから。</p>
        </body>
        </html>
        HTML;
    }

    public function test_it_extracts_title_information(): void
    {
        $result = $this->analyzer->analyze($this->sampleHtml(), 'https://example.com/');

        $this->assertTrue($result['title']['present']);
        $this->assertSame('サンプルページ | テスト', $result['title']['text']);
        $this->assertSame(1, $result['title']['count']);
    }

    public function test_it_extracts_meta_description(): void
    {
        $result = $this->analyzer->analyze($this->sampleHtml(), 'https://example.com/');

        $this->assertTrue($result['meta_description']['present']);
        $this->assertSame('これはテスト用の説明文です。', $result['meta_description']['text']);
    }

    public function test_it_counts_h1_tags(): void
    {
        $result = $this->analyzer->analyze($this->sampleHtml(), 'https://example.com/');

        $this->assertSame(1, $result['h1']['count']);
        $this->assertSame(['メインタイトル'], $result['h1']['texts']);
    }

    public function test_it_detects_self_referencing_canonical(): void
    {
        $result = $this->analyzer->analyze($this->sampleHtml(), 'https://example.com/');

        $this->assertTrue($result['canonical']['present']);
        $this->assertTrue($result['canonical']['is_self_referencing']);
    }

    public function test_it_detects_robots_meta(): void
    {
        $result = $this->analyzer->analyze($this->sampleHtml(), 'https://example.com/');

        $this->assertTrue($result['robots_meta']['index']);
        $this->assertTrue($result['robots_meta']['follow']);
    }

    public function test_it_defaults_to_indexable_when_robots_meta_is_absent(): void
    {
        $result = $this->analyzer->analyze('<html><head></head><body></body></html>', 'https://example.com/');

        $this->assertFalse($result['robots_meta']['present']);
        $this->assertTrue($result['robots_meta']['index']);
    }

    public function test_it_calculates_image_alt_coverage(): void
    {
        $result = $this->analyzer->analyze($this->sampleHtml(), 'https://example.com/');

        $this->assertSame(3, $result['images']['total']);
        $this->assertSame(1, $result['images']['with_alt']);
        $this->assertSame(1, $result['images']['empty_alt']);
        $this->assertSame(1, $result['images']['missing_alt']);
        $this->assertEqualsWithDelta(0.3333, $result['images']['alt_coverage'], 0.001);
    }

    public function test_it_counts_link_types(): void
    {
        $result = $this->analyzer->analyze($this->sampleHtml(), 'https://example.com/');

        $this->assertSame(1, $result['links']['mailto']);
        $this->assertSame(1, $result['links']['tel']);
        $this->assertSame(1, $result['links']['sns']);
        $this->assertGreaterThanOrEqual(1, $result['links']['contact_like']);
        // https://external.com/ と https://twitter.com/example の2件が外部リンク
        // (SNSリンクは「外部リンクでもある」ため両方にカウントされる)
        $this->assertSame(2, $result['links']['external']);
    }

    public function test_it_detects_viewport_and_favicon(): void
    {
        $result = $this->analyzer->analyze($this->sampleHtml(), 'https://example.com/');

        $this->assertTrue($result['content']['viewport_present']);
        $this->assertTrue($result['content']['favicon_present']);
        $this->assertSame('ja', $result['content']['lang']);
    }

    public function test_it_parses_structured_data(): void
    {
        $result = $this->analyzer->analyze($this->sampleHtml(), 'https://example.com/');

        $this->assertSame(1, $result['structured_data']['count']);
        $this->assertSame(['Organization'], $result['structured_data']['types']);
        $this->assertSame(0, $result['structured_data']['parse_errors']);
    }

    public function test_it_reports_json_ld_parse_errors(): void
    {
        $html = '<html><head><script type="application/ld+json">{invalid json}</script></head><body></body></html>';
        $result = $this->analyzer->analyze($html, 'https://example.com/');

        $this->assertSame(1, $result['structured_data']['parse_errors']);
    }

    public function test_it_detects_form_and_cta_information(): void
    {
        $result = $this->analyzer->analyze($this->sampleHtml(), 'https://example.com/');

        $this->assertSame(1, $result['forms']['form_count']);
        // input[type=submit] と type未指定のbutton (HTML仕様上デフォルトでsubmit) の2件。
        $this->assertSame(2, $result['forms']['submit_count']);
        $this->assertTrue($result['forms']['contact_like']);
        $this->assertTrue($result['forms']['reservation_like']);
    }

    public function test_it_handles_multiple_h1_and_missing_title(): void
    {
        $html = '<html><body><h1>A</h1><h1>B</h1></body></html>';
        $result = $this->analyzer->analyze($html, 'https://example.com/');

        $this->assertSame(2, $result['h1']['count']);
        $this->assertFalse($result['title']['present']);
    }

    public function test_it_detects_business_links_with_confidence_when_href_and_text_both_match(): void
    {
        $html = '<html><body>'
            .'<a href="/pricing">料金プラン</a>'
            .'<a href="/faq">よくある質問</a>'
            .'<a href="/case-study">導入事例</a>'
            .'<a href="/company">会社概要</a>'
            .'<a href="/privacy">プライバシーポリシー</a>'
            .'<a href="/recruit">採用情報</a>'
            .'</body></html>';

        $result = $this->analyzer->analyze($html, 'https://example.com/');

        foreach (['pricing', 'faq', 'case_study', 'company_info', 'privacy_policy', 'recruit'] as $category) {
            $this->assertTrue($result['business_links'][$category]['present'], "expected {$category} to be detected");
            $this->assertEqualsWithDelta(0.95, $result['business_links'][$category]['confidence'], 0.001);
            $this->assertSame('internal', $result['business_links'][$category]['link_type']);
        }
    }

    public function test_it_gives_lower_confidence_when_only_the_href_matches_a_business_link_keyword(): void
    {
        // リンクテキストが一致しない(URLのみの一致)場合は確信度を100%にしない。
        $html = '<html><body><a href="/pricing">詳しくはこちら</a></body></html>';
        $result = $this->analyzer->analyze($html, 'https://example.com/');

        $this->assertTrue($result['business_links']['pricing']['present']);
        $this->assertLessThan(0.95, $result['business_links']['pricing']['confidence']);
    }

    public function test_it_does_not_detect_business_links_that_are_absent(): void
    {
        $result = $this->analyzer->analyze('<html><body><a href="/other">別ページ</a></body></html>', 'https://example.com/');

        $this->assertFalse($result['business_links']['pricing']['present']);
        $this->assertNull($result['business_links']['pricing']['url']);
    }

    public function test_it_detects_a_known_third_party_reservation_service(): void
    {
        $html = '<html><body><a href="https://www.tablecheck.com/shops/example">ご予約はこちら</a></body></html>';
        $result = $this->analyzer->analyze($html, 'https://example.com/');

        $this->assertTrue($result['third_party_reservation']['detected']);
        $this->assertSame('www.tablecheck.com', $result['third_party_reservation']['host']);
    }

    public function test_it_does_not_flag_an_unknown_external_host_as_a_third_party_reservation_service(): void
    {
        $html = '<html><body><a href="https://unrelated-vendor.example/reserve">予約</a></body></html>';
        $result = $this->analyzer->analyze($html, 'https://example.com/');

        $this->assertFalse($result['third_party_reservation']['detected']);
    }

    public function test_it_classifies_form_input_burden_by_required_field_count(): void
    {
        $html = '<html><body><form>'
            .'<input type="text" name="name" required>'
            .'<input type="email" name="email" required>'
            .'<input type="hidden" name="token" value="x">'
            .'<input type="submit" value="送信">'
            .'</form></body></html>';

        $result = $this->analyzer->analyze($html, 'https://example.com/');

        $this->assertTrue($result['form_burden']['form_found']);
        $this->assertSame(2, $result['form_burden']['required_field_count']);
        $this->assertSame(2, $result['form_burden']['total_field_count']);
        $this->assertSame('small', $result['form_burden']['tier']);
    }

    public function test_it_classifies_a_large_form_as_a_high_input_burden(): void
    {
        $requiredInputs = '';
        for ($i = 0; $i < 11; $i++) {
            $requiredInputs .= "<input type=\"text\" name=\"field{$i}\" required>";
        }
        $html = "<html><body><form>{$requiredInputs}<input type=\"submit\"></form></body></html>";

        $result = $this->analyzer->analyze($html, 'https://example.com/');

        $this->assertSame(11, $result['form_burden']['required_field_count']);
        $this->assertSame('large', $result['form_burden']['tier']);
    }

    public function test_it_reports_no_form_burden_when_there_is_no_form(): void
    {
        $result = $this->analyzer->analyze('<html><body><p>no form here</p></body></html>', 'https://example.com/');

        $this->assertFalse($result['form_burden']['form_found']);
        $this->assertNull($result['form_burden']['required_field_count']);
        $this->assertNull($result['form_burden']['tier']);
    }

    public function test_it_prefers_the_contact_like_form_as_the_representative_form(): void
    {
        $html = '<html><body>'
            .'<form><input type="text" name="q1" required><input type="text" name="q2" required>'
            .'<input type="text" name="q3" required><input type="submit"></form>'
            .'<form><input type="email" name="contact_email" required><input type="submit"></form>'
            .'</body></html>';

        $result = $this->analyzer->analyze($html, 'https://example.com/');

        // 項目数だけなら1つ目のフォーム(3項目)の方が多いが、
        // 「問い合わせらしいフォーム」(email/contact系name)を優先して選ぶ。
        $this->assertSame(2, $result['form_burden']['form_count']);
        $this->assertSame(1, $result['form_burden']['required_field_count']);
    }
}
