<?php

namespace App\Services\Analysis;

/**
 * HTML内で見つけたhref(絶対URL・ルート相対・パス相対・プロトコル相対のいずれか)を、
 * そのページ自身のURLを基準に絶対URLへ解決する。
 *
 * HtmlSeoAnalyzer::analyzeBusinessLinks()が返すURLは、アンカータグのhref属性を
 * そのまま返す(絶対URL化していない)ため、別ページ(採用ページ等)を実際に
 * 取得する前に、この解決が必要になる。SafeHttpFetcher::resolveRedirectTarget()と
 * 同種のロジックだが、あちらはリダイレクトLocationヘッダー専用のprivateメソッド
 * として実装されているため、ここでは独立した小さなユーティリティとして
 * 別に用意する(SSRF対策の中核であるSafeHttpFetcher自体には手を入れない)。
 *
 * 実際のSSRF検証(プライベートIP拒否等)は行わない ―― 解決した絶対URLは
 * 必ずSafeUrlValidator/SafeHttpFetcher側で検証されることが前提。
 */
class RelativeUrlResolver
{
    public function resolve(string $baseUrl, string $href): string
    {
        $href = trim($href);

        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $base = parse_url($baseUrl);

        if ($base === false || ! isset($base['scheme'], $base['host'])) {
            return $href;
        }

        $port = isset($base['port']) ? ':'.$base['port'] : '';
        $origin = "{$base['scheme']}://{$base['host']}{$port}";

        // プロトコル相対URL(//example.com/path)。
        if (str_starts_with($href, '//')) {
            return "{$base['scheme']}:{$href}";
        }

        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        $basePath = isset($base['path']) ? preg_replace('#/[^/]*$#', '/', $base['path']) : '/';

        return $origin.$basePath.$href;
    }
}
