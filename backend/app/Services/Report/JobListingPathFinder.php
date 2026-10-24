<?php

namespace App\Services\Report;

/**
 * 依頼DG(2026-10-23): 階層図に「募集要項・エントリー」への導線をはっきり出すための判定(AIなし・通信しない)。
 *
 * 保存済みのHTML(採用サイトのTOP、巡回した各ページ、コーポレートTOP)のリンクから、募集要項・求人・
 * エントリーへのリンクを探し、いちばん近いもの(採用TOPからのクリック数が少ない → リンクの場所が
 * メニュー → 本文 → フッター → 言葉の一覧の順)を1つ選ぶ。
 *
 * 募集要項へのリンクとみなすのは、次のどれか(一覧はすべて config、一般的な語だけ):
 *  - リンクの文字が site_hierarchy_job_listing_words のどれかを含む
 *  - リンク先のパスが site_hierarchy_job_listing_path_words のどれかを含む
 *  - リンク先のホストが site_hierarchy_job_listing_hosts(外部の応募受付サービス)のどれかで終わる
 *
 * 見つからなくても「無い」とは言わない(巡回した範囲で確認できなかった、だけ)。
 */
class JobListingPathFinder
{
    private const PLACEMENT_RANK = ['menu' => 0, 'body' => 1, 'footer' => 2];

    /**
     * @param  list<array{html: string, base_url: string, clicks: int, source: string}>  $documents
     *                                                                                       source: recruit_top | crawled | corporate_top
     * @param  list<string>  $internalHosts  採用サイト・コーポレートサイトのホスト(これ以外は外部)
     * @return array{found: bool, label?: string, url?: string, host?: string, external?: bool, service?: ?string, clicks?: int, placement?: string, source?: string}
     */
    public function find(array $documents, array $internalHosts): array
    {
        $internalHosts = array_values(array_unique(array_map('strtolower', array_filter($internalHosts))));
        $best = null;
        $bestKey = null;
        $order = 0;

        foreach ($documents as $document) {
            foreach ($this->links($document['html'], $document['base_url']) as $link) {
                $wordRank = $this->matchRank($link['url'], $link['text']);
                if ($wordRank === null) {
                    continue;
                }

                // コーポレートTOPのリンクは、採用サイト側で見つからなかったときだけ使う(source の順)。
                $sourceRank = $document['source'] === 'corporate_top' ? 1 : 0;
                // 依頼DI: ニュース・ブログの記事へのリンクは、ほかに候補があればその後(コーポレートTOPのものよりは先)。
                $key = [$sourceRank, $this->isDeprioritized($link['url']) ? 1 : 0, $document['clicks'], self::PLACEMENT_RANK[$link['placement']], $wordRank, $order++];
                if ($bestKey === null || $key < $bestKey) {
                    $bestKey = $key;
                    $host = strtolower((string) parse_url($link['url'], PHP_URL_HOST));
                    $best = [
                        'found' => true,
                        'label' => $link['text'],
                        'url' => $link['url'],
                        'host' => $host,
                        'external' => ! in_array($host, $internalHosts, true),
                        'service' => $this->serviceName($host),
                        'clicks' => $document['clicks'],
                        'placement' => $link['placement'],
                        'source' => $document['source'],
                    ];
                }
            }
        }

        return $best ?? ['found' => false];
    }

    /**
     * 募集要項へのリンクなら、言葉の一覧での順位(小さいほど強い)。違えば null。
     */
    public function matchRank(string $url, string $text): ?int
    {
        $text = mb_strtolower(trim($text));
        foreach (array_values((array) config('admin_comparison_pptx.site_hierarchy_job_listing_words', [])) as $i => $word) {
            $word = mb_strtolower(trim((string) $word));
            if ($word !== '' && $text !== '' && mb_strpos($text, $word) !== false) {
                return $i;
            }
        }

        $base = count((array) config('admin_comparison_pptx.site_hierarchy_job_listing_words', []));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (array_keys((array) config('admin_comparison_pptx.site_hierarchy_job_listing_hosts', [])) as $suffix) {
            $suffix = strtolower((string) $suffix);
            if ($suffix !== '' && ($host === $suffix || str_ends_with($host, '.'.$suffix))) {
                return $base;
            }
        }

        $path = mb_strtolower(rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?? '')));
        foreach (array_values((array) config('admin_comparison_pptx.site_hierarchy_job_listing_path_words', [])) as $i => $word) {
            $word = mb_strtolower(trim((string) $word));
            if ($word !== '' && mb_strpos($path, $word) !== false) {
                return $base + 1 + $i;
            }
        }

        return null;
    }

    /**
     * 依頼DI: リンク先のパスの区切りに、後回しにする語(ニュース・ブログなど、config)があるか。
     */
    public function isDeprioritized(string $url): bool
    {
        $segments = array_map(fn (string $s) => mb_strtolower(rawurldecode($s)), explode('/', trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/')));
        $words = array_map(fn ($w) => mb_strtolower(trim((string) $w)), (array) config('admin_comparison_pptx.site_hierarchy_job_listing_deprioritized_path_segments', []));

        return array_intersect($segments, array_filter($words)) !== [];
    }

    /**
     * 外部の応募受付サービスの表示名(config に名前があるときだけ)。
     */
    private function serviceName(string $host): ?string
    {
        foreach ((array) config('admin_comparison_pptx.site_hierarchy_job_listing_hosts', []) as $suffix => $name) {
            $suffix = strtolower((string) $suffix);
            if ($suffix !== '' && ($host === $suffix || str_ends_with($host, '.'.$suffix))) {
                $name = trim((string) $name);

                return $name !== '' ? $name : null;
            }
        }

        return null;
    }

    /**
     * @return list<array{url: string, text: string, placement: string}>
     */
    private function links(string $html, string $baseUrl): array
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOENT | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $links = [];
        foreach ((new \DOMXPath($dom))->query('//a[@href]') ?: [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $url = $this->resolve($baseUrl, trim($node->getAttribute('href')));
            if ($url === null) {
                continue;
            }

            $text = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
            if ($text === '') {
                foreach ($node->getElementsByTagName('img') as $img) {
                    $text = trim($img->getAttribute('alt'));
                    if ($text !== '') {
                        break;
                    }
                }
            }
            if ($text === '') {
                $text = trim($node->getAttribute('aria-label') ?: $node->getAttribute('title'));
            }

            $links[] = ['url' => $url, 'text' => $text, 'placement' => $this->placementOf($node)];
        }

        return $links;
    }

    private function placementOf(\DOMElement $node): string
    {
        for ($parent = $node->parentNode; $parent instanceof \DOMElement; $parent = $parent->parentNode) {
            $tag = strtolower($parent->tagName);
            if ($tag === 'footer' || strtolower($parent->getAttribute('role')) === 'contentinfo') {
                return 'footer';
            }
            if ($tag === 'header' || $tag === 'nav' || in_array(strtolower($parent->getAttribute('role')), ['navigation', 'banner'], true)) {
                return 'menu';
            }
        }

        return 'body';
    }

    private function resolve(string $baseUrl, string $href): ?string
    {
        // 巡回と同じ解決規則(fragmentのみ・mailto:・tel:・javascript:・空は null)。
        return app(\App\Services\Analysis\CrawlLinkExtractor::class)->resolveHref($baseUrl, $href);
    }
}
