<?php

namespace App\Services\CorporateTop;

use App\Services\Analysis\CrawlLinkExtractor;
use App\Services\Analysis\HtmlSeoAnalyzer;

/**
 * 依頼CR-1: コーポレートTOPのHTMLを読む(通信しない、同じ入力から同じ結果)。
 *  - 採用サイトの起点へのリンクを探す(あるときだけ、そのページをコーポレートTOPとして採用する)。
 *  - 採用以外のメニューの項目名を、名前だけ取り出す。
 */
class CorporateTopAnalyzer
{
    public function __construct(
        private readonly CrawlLinkExtractor $linkExtractor = new CrawlLinkExtractor,
        private readonly HtmlSeoAnalyzer $htmlAnalyzer = new HtmlSeoAnalyzer,
    ) {}

    /**
     * ページ内の<a>のうち、リンク先が採用サイトの起点と同じか、その配下のもの。選び方(次の順):
     *  1. 起点そのものへのリンク
     *  2. リンクの文字が空でなく、長さの上限(config 'corporate_top_label_max_chars')以内のもの
     *     (バナーのような長い文章は、採用の箱の名前にしない)
     *  3. リンク先のパスが浅いもの(起点に近いもの)
     *  4. 文書順で先のもの
     * リンクの文字は、見えている文字 → <img>のalt → aria-label → title属性の順。選んだリンクの文字が
     * 空、または長さの上限を超えるときは、リンクの文字はnull(呼び出し側がconfigの既定を使う ―― 文字を作らない)。
     *
     * @param  array{origin_url: string, host: string, path: string}  $scope  CrawlOriginScopeResolver::resolveScope()
     * @return ?array{label: ?string, url: string}
     */
    public function findRecruitLink(string $html, string $baseUrl, array $scope): ?array
    {
        $originKey = $this->key($scope['origin_url']);
        $max = (int) config('admin_comparison_pptx.corporate_top_label_max_chars');

        $candidates = [];
        foreach ($this->links($html) as $order => $link) {
            $url = $this->linkExtractor->resolveHref($baseUrl, $link['href']);
            if ($url === null || ! $this->isWithin($url, $scope)) {
                continue;
            }

            $label = $link['label'];
            $labelOk = $label !== null && ($max <= 0 || mb_strlen($label) <= $max);
            $path = trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');
            $candidates[] = [
                'rank' => [$this->key($url) === $originKey ? 0 : 1, $labelOk ? 0 : 1, $path === '' ? 0 : substr_count($path, '/') + 1, $order],
                'label' => $labelOk ? $label : null,
                'url' => $url,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);

        return ['label' => $candidates[0]['label'], 'url' => $candidates[0]['url']];
    }

    /**
     * コーポレートTOPのメニュー(HtmlSeoAnalyzer::extractMenuLinks()、階層図のメニューと同じ読み方)の項目名のうち、
     * 採用サイトへのもの(起点の配下、または採用の箱の名前と同じ文字)を除いたもの。重複は除く。名前だけ返す。
     *
     * @param  array{origin_url: string, host: string, path: string}  $scope
     * @return list<string>
     */
    public function otherMenuLabels(string $html, string $baseUrl, array $scope, ?string $recruitLabel): array
    {
        return $this->otherMenu($html, null, $baseUrl, $scope, $recruitLabel)['labels'];
    }

    /**
     * 依頼CY-1: コーポレートの他メニューを、次の順で取る(取れた = 下の除外を通った項目が1件以上ある、時点で止める。通信しない)。
     *  1. 静的なHTMLの header / nav(HtmlSeoAnalyzer::extractMenuLinks、これまでと同じ)
     *  2. 描画済みのHTML(取得のときに保存したもの)の header / nav
     *  3. class / id にナビを表す語(config)を含む要素(main・article・footerの外)の中のリンク。描画済みのHTMLがあればそちら、無ければ静的なHTML
     *
     * 除くもの(config、理由ごとに件数を数える): 取れなかったURL(unresolved)・採用サイトの配下(recruit_scope)・採用の箱と同じ名前
     * (recruit_label)・サイトのTOP自身へのリンク(site_top。ロゴ・HOME)・他のホスト(other_host。wwwの有無は同じホスト)・
     * 情報の区分ではない項目(not_a_section: 言語切り替え・検索・
     * ログイン・お問い合わせ・サイトマップなど)・重複(duplicate)。
     *
     * @param  array{origin_url: string, host: string, path: string}  $scope
     * @return array{labels: list<string>, source: ?string, raw_counts: array<string, int>, excluded: array<string, array<string, int>>}
     */
    public function otherMenu(string $staticHtml, ?string $renderedHtml, string $baseUrl, array $scope, ?string $recruitLabel): array
    {
        $steps = [
            'static_nav' => fn () => $this->htmlAnalyzer->extractMenuLinks($staticHtml),
            'rendered_nav' => fn () => $renderedHtml !== null ? $this->htmlAnalyzer->extractMenuLinks($renderedHtml) : [],
            'class_words' => fn () => $this->classWordLinks($renderedHtml ?? $staticHtml),
        ];

        $rawCounts = [];
        $excluded = [];
        foreach ($steps as $source => $get) {
            $links = $get();
            $rawCounts[$source] = count($links);
            $reasons = [];
            $labels = $this->filterMenu($links, $baseUrl, $scope, $recruitLabel, $reasons);
            $excluded[$source] = $reasons;
            if ($labels !== []) {
                return ['labels' => $labels, 'source' => $source, 'raw_counts' => $rawCounts, 'excluded' => $excluded];
            }
        }

        return ['labels' => [], 'source' => null, 'raw_counts' => $rawCounts, 'excluded' => $excluded];
    }

    /**
     * @param  list<array{label: string, href: string}>  $links
     * @param  array{origin_url: string, host: string, path: string}  $scope
     * @param  array<string, int>  $reasons  除いた理由ごとの件数(参照で返す)
     * @return list<string>
     */
    private function filterMenu(array $links, string $baseUrl, array $scope, ?string $recruitLabel, array &$reasons): array
    {
        $baseHost = $this->bareHost($baseUrl);
        $sameHostOnly = (bool) config('admin_comparison_pptx.corporate_top_menu_same_host_only', true);

        $labels = [];
        foreach ($links as $link) {
            $label = trim((string) preg_replace('/\s+/u', ' ', (string) $link['label']));
            $url = $this->linkExtractor->resolveHref($baseUrl, (string) $link['href']);

            $reason = null;
            if ($url === null || $label === '') {
                $reason = 'unresolved';
            } elseif ($this->isWithin($url, $scope)) {
                $reason = 'recruit_scope';
            } elseif ($label === $recruitLabel) {
                $reason = 'recruit_label';
            } elseif ($this->isSiteTop($url, $baseHost)) {
                $reason = 'site_top';
            } elseif ($sameHostOnly && $this->bareHost($url) !== $baseHost) {
                $reason = 'other_host';
            } elseif ($this->isNotASection($label)) {
                $reason = 'not_a_section';
            } elseif (in_array($label, $labels, true)) {
                $reason = 'duplicate';
            }

            if ($reason !== null) {
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;

                continue;
            }
            $labels[] = $label;
        }

        return $labels;
    }

    /**
     * class / id にナビを表す語を含む要素(main・article・footerの外)の中のリンク(文書順)。
     *
     * @return list<array{label: string, href: string}>
     */
    private function classWordLinks(string $html): array
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOENT | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $words = array_map('strtolower', (array) config('admin_comparison_pptx.corporate_top_menu_nav_words', []));
        $maxDepth = (int) config('admin_comparison_pptx.corporate_top_menu_class_words_max_list_depth', 1);

        $links = [];
        $seen = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            if (! $anchor instanceof \DOMElement || ! $this->insideNavWordElement($anchor, $words) || $this->listDepth($anchor) > $maxDepth) {
                continue;
            }
            $label = $this->labelOf($anchor);
            $key = $label.'|'.$anchor->getAttribute('href');
            if ($label === null || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $links[] = ['label' => mb_substr($label, 0, 64), 'href' => trim($anchor->getAttribute('href'))];
        }

        return $links;
    }

    /** リンクの祖先の li の数(リストの入れ子の深さ)。サブメニュー(メニューの子)は、親の li の中の li になる。 */
    private function listDepth(\DOMElement $anchor): int
    {
        $depth = 0;
        for ($node = $anchor->parentNode; $node instanceof \DOMElement; $node = $node->parentNode) {
            if (strtolower($node->tagName) === 'li') {
                $depth++;
            }
        }

        return $depth;
    }

    /**
     * リンクの祖先に、class / id がナビの語を含む要素があり、かつ main・article・footer の中でない。
     *
     * @param  list<string>  $words
     */
    private function insideNavWordElement(\DOMElement $anchor, array $words): bool
    {
        $matched = false;
        for ($node = $anchor->parentNode; $node instanceof \DOMElement; $node = $node->parentNode) {
            if (in_array(strtolower($node->tagName), ['main', 'article', 'footer'], true)) {
                return false;
            }
            if (! $matched) {
                $names = strtolower($node->getAttribute('class').' '.$node->getAttribute('id'));
                foreach ($words as $word) {
                    if ($word !== '' && str_contains($names, $word)) {
                        $matched = true;

                        break;
                    }
                }
            }
        }

        return $matched;
    }

    /** 情報の区分ではない項目(言語切り替え・検索・ログイン・お問い合わせ・サイトマップなど)。 */
    private function isNotASection(string $label): bool
    {
        $lower = mb_strtolower($label);
        if (in_array($lower, array_map('mb_strtolower', (array) config('admin_comparison_pptx.corporate_top_menu_exclude_exact', [])), true)) {
            return true;
        }
        foreach ((array) config('admin_comparison_pptx.corporate_top_menu_exclude_contains', []) as $word) {
            if ($word !== '' && str_contains($lower, mb_strtolower((string) $word))) {
                return true;
            }
        }

        return false;
    }

    /** 同じホストのTOP自身(/・/index.html)へのリンク。メニューの項目ではなく、ロゴ・HOMEのリンク。 */
    private function isSiteTop(string $url, string $baseHost): bool
    {
        $parts = parse_url($url);
        if ($this->bareHost($url) !== $baseHost) {
            return false;
        }

        return (bool) preg_match('#^/?(?:index\.(?:html?|php))?$#i', (string) ($parts['path'] ?? ''));
    }

    private function bareHost(string $url): string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * @return list<array{href: string, label: ?string}>
     */
    private function links(string $html): array
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
            $links[] = ['href' => trim($node->getAttribute('href')), 'label' => $this->labelOf($node)];
        }

        return $links;
    }

    private function labelOf(\DOMElement $anchor): ?string
    {
        $text = $this->collapse($anchor->textContent);
        if ($text !== '') {
            return $text;
        }

        foreach ($anchor->getElementsByTagName('img') as $img) {
            $alt = $this->collapse($img->getAttribute('alt'));
            if ($alt !== '') {
                return $alt;
            }
        }

        foreach (['aria-label', 'title'] as $attribute) {
            $value = $this->collapse($anchor->getAttribute($attribute));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * リンク先が、起点と同じホストで、起点のパスと同じか配下か。
     *
     * @param  array{origin_url: string, host: string, path: string}  $scope
     */
    private function isWithin(string $url, array $scope): bool
    {
        $parts = parse_url($url);
        if (strtolower((string) ($parts['host'] ?? '')) !== $scope['host']) {
            return false;
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/').'/';

        return str_starts_with($path, rtrim($scope['path'], '/').'/');
    }

    private function key(string $url): string
    {
        $parts = parse_url($url);

        return strtolower((string) ($parts['host'] ?? '')).rtrim((string) ($parts['path'] ?? ''), '/');
    }
}
