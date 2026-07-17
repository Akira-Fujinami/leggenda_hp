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
}
