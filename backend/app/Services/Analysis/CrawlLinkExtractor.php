<?php

namespace App\Services\Analysis;

/**
 * サイト全ページ巡回(依頼C・Phase 1)専用のリンク抽出。HtmlSeoAnalyzerが
 * 既に持つanalyzeBusinessLinks()等はカテゴリ判定込みの重い処理であり、
 * かつそのチューニングされたロジックに巡回の都合で手を入れたくないため、
 * 意図的に分離した最小限の実装(href一覧を返すだけ)にする。
 *
 * HtmlSeoAnalyzer::loadDomForTextExtraction()と同じ安全なパース方針
 * (LIBXML_NONET、内部エラーは抑制)を踏襲する。
 */
class CrawlLinkExtractor
{
    /**
     * ページ内の<a href>をすべて絶対URLへ解決して返す(重複除去済み)。
     * fragment(#…)・mailto:・tel:・javascript:は除外する。
     *
     * @return list<string>
     */
    public function extractAbsoluteLinks(string $html, string $pageUrl): array
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8"?>'.$html,
            LIBXML_NOENT | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//a[@href]');

        $links = [];
        foreach ($nodes ?? [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $href = trim($node->getAttribute('href'));

            if ($href === '' || str_starts_with($href, '#')
                || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')
                || str_starts_with($href, 'javascript:')) {
                continue;
            }

            $resolved = $this->resolveAbsoluteUrl($pageUrl, $href);

            if ($resolved !== null) {
                $links[$resolved] = true;
            }
        }

        return array_keys($links);
    }

    private function resolveAbsoluteUrl(string $pageUrl, string $href): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $this->stripFragment($href);
        }

        // プロトコル相対URL(//example.com/path)。
        if (str_starts_with($href, '//')) {
            $scheme = parse_url($pageUrl, PHP_URL_SCHEME) ?: 'https';

            return $this->stripFragment("{$scheme}:{$href}");
        }

        $base = parse_url($pageUrl);
        if ($base === false || ! isset($base['scheme'], $base['host'])) {
            return null;
        }

        $port = isset($base['port']) ? ':'.$base['port'] : '';
        $origin = "{$base['scheme']}://{$base['host']}{$port}";

        if (str_starts_with($href, '/')) {
            return $this->stripFragment($origin.$href);
        }

        $basePath = isset($base['path']) ? (preg_replace('#/[^/]*$#', '/', $base['path']) ?? '/') : '/';

        return $this->stripFragment($origin.$basePath.$href);
    }

    private function stripFragment(string $url): string
    {
        $pos = strpos($url, '#');

        return $pos === false ? $url : substr($url, 0, $pos);
    }
}
