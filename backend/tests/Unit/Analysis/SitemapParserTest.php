<?php

namespace Tests\Unit\Analysis;

use App\Services\Analysis\SitemapParser;
use Tests\TestCase;

class SitemapParserTest extends TestCase
{
    private SitemapParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SitemapParser;
    }

    public function test_it_parses_a_urlset(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
            <url><loc>https://example.com/</loc></url>
            <url><loc>https://example.com/about</loc></url>
        </urlset>
        XML;

        $result = $this->parser->parse($xml);

        $this->assertSame('urlset', $result['kind']);
        $this->assertSame(2, $result['url_count']);
        $this->assertFalse($result['parse_error']);
    }

    public function test_it_parses_a_sitemapindex(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
            <sitemap><loc>https://example.com/sitemap-1.xml</loc></sitemap>
            <sitemap><loc>https://example.com/sitemap-2.xml</loc></sitemap>
            <sitemap><loc>https://example.com/sitemap-3.xml</loc></sitemap>
        </sitemapindex>
        XML;

        $result = $this->parser->parse($xml);

        $this->assertSame('sitemapindex', $result['kind']);
        $this->assertSame(3, $result['sitemap_count']);
    }

    public function test_it_reports_parse_error_for_malformed_xml(): void
    {
        $result = $this->parser->parse('<urlset><url><loc>broken');

        $this->assertTrue($result['parse_error']);
    }

    public function test_it_reports_parse_error_for_unrecognized_root_element(): void
    {
        $result = $this->parser->parse('<?xml version="1.0"?><rss><channel></channel></rss>');

        $this->assertNull($result['kind']);
        $this->assertTrue($result['parse_error']);
    }

    public function test_it_does_not_expand_internal_entities(): void
    {
        // billion-laughs型の内部実体参照展開を試みるXML。
        // 例外やハングを起こさず、安全に処理できることを確認する。
        $xml = <<<'XML'
        <?xml version="1.0"?>
        <!DOCTYPE urlset [
            <!ENTITY a "1234567890">
            <!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">
        ]>
        <urlset><url><loc>&b;</loc></url></urlset>
        XML;

        $result = $this->parser->parse($xml);

        // 実体が展開されて巨大な文字列になっていないことを確認
        $this->assertSame('urlset', $result['kind']);
        $this->assertSame(1, $result['url_count']);
    }
}
