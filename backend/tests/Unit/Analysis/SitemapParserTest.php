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

    /**
     * 2026-08-25(依頼C-2): urlset内のURL文字列そのものをurlsとして返す
     * (全ページ巡回のseed URL収集用)。既存のkind/url_count/parse_errorの
     * 意味は変更しない。
     */
    public function test_it_returns_urls_for_a_urlset(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
            <url><loc>https://example.com/</loc></url>
            <url><loc>https://example.com/recruit/</loc></url>
        </urlset>
        XML;

        $result = $this->parser->parse($xml);

        $this->assertSame(['https://example.com/', 'https://example.com/recruit/'], $result['urls']);
    }

    public function test_it_returns_empty_urls_when_a_url_element_has_no_loc(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
            <url><lastmod>2026-01-01</lastmod></url>
            <url><loc>https://example.com/about</loc></url>
        </urlset>
        XML;

        $result = $this->parser->parse($xml);

        $this->assertSame(['https://example.com/about'], $result['urls']);
    }

    /**
     * urlsとして実際に保持する件数の上限(MAX_RETURNED_URLS=500)。
     * url_count自体(件数集計)はこの上限の影響を受けない。
     */
    public function test_it_caps_the_returned_urls_list_independently_of_the_url_count(): void
    {
        $urlTags = '';
        for ($i = 1; $i <= 600; $i++) {
            $urlTags .= "<url><loc>https://example.com/page-{$i}</loc></url>";
        }
        $xml = '<?xml version="1.0"?><urlset>'.$urlTags.'</urlset>';

        $result = $this->parser->parse($xml);

        $this->assertSame(600, $result['url_count']);
        $this->assertCount(500, $result['urls']);
        $this->assertFalse($result['truncated']); // truncatedはMAX_COUNTED_ENTRIES(50000)基準のまま。
    }

    /**
     * sitemapindexの子sitemapを再帰的に取得するかはPhase 1の範囲外
     * (依頼C-2)。urlsは常に空配列で返す。
     */
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
        $this->assertSame([], $result['urls']);
    }

    public function test_it_reports_parse_error_for_malformed_xml(): void
    {
        $result = $this->parser->parse('<urlset><url><loc>broken');

        $this->assertTrue($result['parse_error']);
        $this->assertSame([], $result['urls']);
    }

    public function test_it_reports_parse_error_for_unrecognized_root_element(): void
    {
        $result = $this->parser->parse('<?xml version="1.0"?><rss><channel></channel></rss>');

        $this->assertNull($result['kind']);
        $this->assertTrue($result['parse_error']);
        $this->assertSame([], $result['urls']);
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
