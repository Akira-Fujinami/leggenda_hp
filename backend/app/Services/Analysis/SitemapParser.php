<?php

namespace App\Services\Analysis;

/**
 * sitemap.xmlを解析する。MVPではsitemap内のURLを実際にクロールせず、
 * 件数と種別 (sitemapindex/urlset) のみを把握する。
 * XML外部エンティティ・DTDは読み込まない設定でパースし、XXEやXML Bombを防ぐ。
 */
class SitemapParser
{
    private const MAX_COUNTED_ENTRIES = 50000;

    /**
     * @return array{kind: string|null, url_count: int, sitemap_count: int, parse_error: bool, truncated: bool}
     */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        $dom = new \DOMDocument;
        // LIBXML_NOENTを付けない = 実体参照は展開しない。外部エンティティは
        // PHP 8のデフォルト設定で読み込まれないため XXE 対策になる。
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $dom->documentElement === null) {
            return ['kind' => null, 'url_count' => 0, 'sitemap_count' => 0, 'parse_error' => true, 'truncated' => false];
        }

        $rootName = $this->localName($dom->documentElement->nodeName);

        if ($rootName === 'sitemapindex') {
            $count = $dom->documentElement->getElementsByTagName('sitemap')->length;

            return [
                'kind' => 'sitemapindex',
                'url_count' => 0,
                'sitemap_count' => min($count, self::MAX_COUNTED_ENTRIES),
                'parse_error' => $errors !== [],
                'truncated' => $count > self::MAX_COUNTED_ENTRIES,
            ];
        }

        if ($rootName === 'urlset') {
            $count = $dom->documentElement->getElementsByTagName('url')->length;

            return [
                'kind' => 'urlset',
                'url_count' => min($count, self::MAX_COUNTED_ENTRIES),
                'sitemap_count' => 0,
                'parse_error' => $errors !== [],
                'truncated' => $count > self::MAX_COUNTED_ENTRIES,
            ];
        }

        return ['kind' => null, 'url_count' => 0, 'sitemap_count' => 0, 'parse_error' => true, 'truncated' => false];
    }

    private function localName(string $nodeName): string
    {
        $parts = explode(':', $nodeName);

        return strtolower(end($parts));
    }
}
