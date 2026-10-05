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
     * 依頼CL-3: $excludeChromeをtrueにすると、<header>/<nav>/<footer>の
     * 内側のリンクを除いた本文側のリンクだけを返す(階層図の第2階層で、
     * 全ページ共通のメニュー・フッターのリンクを除くため)。既定(false)の
     * 挙動は従来と同じ。
     *
     * @return list<string>
     */
    public function extractAbsoluteLinks(string $html, string $pageUrl, bool $excludeChrome = false): array
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
        $nodes = $xpath->query($excludeChrome
            ? '//a[@href][not(ancestor::header) and not(ancestor::nav) and not(ancestor::footer)]'
            : '//a[@href]');

        $links = [];
        foreach ($nodes ?? [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $resolved = $this->resolveHref($pageUrl, $node->getAttribute('href'));

            if ($resolved !== null) {
                $links[$resolved] = true;
            }
        }

        return array_keys($links);
    }

    /**
     * hrefの生の値を、ページURLを基準に絶対URLへ解決する(依頼CL-3で
     * extractAbsoluteLinks()のループ本体をそのまま切り出して公開した ――
     * 除外規則・解決規則は従来と同一)。fragmentのみ・mailto:・tel:・
     * javascript:・空はnull。
     */
    public function resolveHref(string $pageUrl, string $rawHref): ?string
    {
        $href = trim($rawHref);

        if ($href === '' || str_starts_with($href, '#')
            || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')
            || str_starts_with($href, 'javascript:')) {
            return null;
        }

        return $this->resolveAbsoluteUrl($pageUrl, $href);
    }

    private function resolveAbsoluteUrl(string $pageUrl, string $href): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $this->normalizeDotSegments($this->stripFragment($href));
        }

        // プロトコル相対URL(//example.com/path)。
        if (str_starts_with($href, '//')) {
            $scheme = parse_url($pageUrl, PHP_URL_SCHEME) ?: 'https';

            return $this->normalizeDotSegments($this->stripFragment("{$scheme}:{$href}"));
        }

        $base = parse_url($pageUrl);
        if ($base === false || ! isset($base['scheme'], $base['host'])) {
            return null;
        }

        $port = isset($base['port']) ? ':'.$base['port'] : '';
        $origin = "{$base['scheme']}://{$base['host']}{$port}";

        if (str_starts_with($href, '/')) {
            return $this->normalizeDotSegments($this->stripFragment($origin.$href));
        }

        $basePath = isset($base['path']) ? (preg_replace('#/[^/]*$#', '/', $base['path']) ?? '/') : '/';

        return $this->normalizeDotSegments($this->stripFragment($origin.$basePath.$href));
    }

    private function stripFragment(string $url): string
    {
        $pos = strpos($url, '#');

        return $pos === false ? $url : substr($url, 0, $pos);
    }

    /**
     * 依頼CD-4: 「../」を含むURLがそのまま(未解決のまま)
     * analysis_crawled_pagesに保存され、階層図の枝名が".."になったり
     * (site_hierarchy_scope_noteの背景で報告)、実体としては同じページが
     * 別のURL文字列として二重に巡回対象になったりする不具合の修正
     * (url_hash一意制約はURL文字列そのものに対する一意性であり、
     * ../有無で文字列が異なれば別ページとして扱われてしまう ―― CD-4調査
     * 参照)。resolveAbsoluteUrl()の全分岐(絶対href・プロトコル相対href・
     * サイト内絶対パスhref・相対href)がここを通るようにし、URL構築箇所を
     * 1つに保つ(依頼CB-3のsite_hierarchy_scope_noteの背景説明と同じ
     * 「定義を1箇所に保つ」方針)。
     *
     * RFC3986 5.2.4(remove_dot_segments)のうち、この用途(既に絶対URLに
     * 組み立てた後のパス正規化)で必要な範囲だけを実装する ―― クエリ文字列
     * ・オリジン(scheme://host[:port])部分はそのまま保つ(捏造・改変
     * しない)。「/../」でルートより上に出ようとする場合は、それ以上遡らず
     * 静かに無視する(存在しないURLを作らない)。
     */
    private function normalizeDotSegments(string $url): string
    {
        if (! preg_match('#^(https?://[^/]+)(/.*)?$#i', $url, $m)) {
            // オリジンを取り出せない形(理論上到達しない、呼び出し元は
            // 必ずhttps?://で始まるURLを渡す)は正規化せずそのまま返す。
            return $url;
        }

        $origin = $m[1];
        $rest = $m[2] ?? '';

        $queryPos = strpos($rest, '?');
        $path = $queryPos === false ? $rest : substr($rest, 0, $queryPos);
        $query = $queryPos === false ? '' : substr($rest, $queryPos);

        return $origin.$this->removeDotSegments($path).$query;
    }

    /**
     * @see normalizeDotSegments()
     */
    private function removeDotSegments(string $path): string
    {
        if ($path === '' || ! str_contains($path, '.')) {
            // 「.」を1文字も含まないパスに「.」「..」セグメントは
            // 存在しえない ―― 大多数のURLはここで既存と全く同じ文字列を
            // 返す(挙動を変えない範囲を最小にする)。
            return $path;
        }

        $segments = explode('/', $path);
        $output = [];
        $lastWasDotSegment = false;

        foreach ($segments as $segment) {
            if ($segment === '.') {
                $lastWasDotSegment = true;

                continue;
            }

            if ($segment === '..') {
                $lastWasDotSegment = true;
                // 出力の末尾が空文字(=ルート直後)でなければ1つ遡る。
                // ルートより上には出ない(存在しないURLを作らない、
                // 依頼者の「禁止事項」に沿う)。
                if ($output !== [] && end($output) !== '') {
                    array_pop($output);
                }

                continue;
            }

            $lastWasDotSegment = false;
            $output[] = $segment;
        }

        // RFC3986 5.2.4: 最後のセグメントが「.」「..」だった場合、
        // ディレクトリを指すことになるため末尾に「/」を残す。
        if ($lastWasDotSegment) {
            $output[] = '';
        }

        return implode('/', $output);
    }
}
