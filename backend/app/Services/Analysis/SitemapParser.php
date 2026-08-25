<?php

namespace App\Services\Analysis;

/**
 * sitemap.xmlを解析する。件数と種別(sitemapindex/urlset)に加え、
 * 2026-08-25(依頼C-2)からurlset内のURL文字列そのものも返す
 * (全ページ巡回のseed URL収集用)。
 * XML外部エンティティ・DTDは読み込まない設定でパースし、XXEやXML Bombを防ぐ。
 */
class SitemapParser
{
    private const MAX_COUNTED_ENTRIES = 50000;

    /**
     * urlsとして実際に返す件数の上限。クロール上限(config('brand_wheel.
     * crawl_max_pages')、既定50)を大きく超える件数をメモリに載せないための
     * 保険であり、MAX_COUNTED_ENTRIES(件数集計の上限、50000)とは別物 ――
     * 件数(url_count)はMAX_COUNTED_ENTRIESまで正確に数え続けるが、実際に
     * 文字列として保持するのはこの件数までに絞る。
     */
    private const MAX_RETURNED_URLS = 500;

    /**
     * @return array{kind: string|null, url_count: int, sitemap_count: int, parse_error: bool, truncated: bool, urls: list<string>}
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
            return ['kind' => null, 'url_count' => 0, 'sitemap_count' => 0, 'parse_error' => true, 'truncated' => false, 'urls' => []];
        }

        $rootName = $this->localName($dom->documentElement->nodeName);

        if ($rootName === 'sitemapindex') {
            $count = $dom->documentElement->getElementsByTagName('sitemap')->length;

            // 依頼C-2: sitemapindexの子sitemapを再帰的に取得するかはPhase 1の
            // 範囲外(中間測定の結果で判断する)。urlsは常に空配列で返す。
            return [
                'kind' => 'sitemapindex',
                'url_count' => 0,
                'sitemap_count' => min($count, self::MAX_COUNTED_ENTRIES),
                'parse_error' => $errors !== [],
                'truncated' => $count > self::MAX_COUNTED_ENTRIES,
                'urls' => [],
            ];
        }

        if ($rootName === 'urlset') {
            $urlNodes = $dom->documentElement->getElementsByTagName('url');
            $count = $urlNodes->length;

            $urls = [];
            foreach ($urlNodes as $urlNode) {
                if (count($urls) >= self::MAX_RETURNED_URLS) {
                    break;
                }

                $locNodes = $urlNode->getElementsByTagName('loc');
                if ($locNodes->length === 0) {
                    continue;
                }

                $loc = trim($locNodes->item(0)?->textContent ?? '');
                if ($loc !== '') {
                    $urls[] = $loc;
                }
            }

            return [
                'kind' => 'urlset',
                'url_count' => min($count, self::MAX_COUNTED_ENTRIES),
                'sitemap_count' => 0,
                'parse_error' => $errors !== [],
                'truncated' => $count > self::MAX_COUNTED_ENTRIES,
                'urls' => $urls,
            ];
        }

        return ['kind' => null, 'url_count' => 0, 'sitemap_count' => 0, 'parse_error' => true, 'truncated' => false, 'urls' => []];
    }

    private function localName(string $nodeName): string
    {
        $parts = explode(':', $nodeName);

        return strtolower(end($parts));
    }
}
