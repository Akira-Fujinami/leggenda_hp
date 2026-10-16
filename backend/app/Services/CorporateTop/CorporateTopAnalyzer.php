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
     *  1. 静的なHTMLの header / nav
     *  2. 描画済みのHTML(取得のときに保存したもの)の header / nav
     *  3. class / id にナビを表す語(config)を含む要素(main・article・footerの外)の中のリンク。描画済みのHTMLがあればそちら、無ければ静的なHTML
     * 依頼DA-2: どの取り方も、メニューの「1段目の項目」だけを取る(firstLevelLinks)。サブメニュー(ドロップダウン・メガメニュー)の中は取らない。
     * 除いたあとの1段目の項目がconfig('admin_comparison_pptx.corporate_top_menu_first_level_max')を超えたら、メニューの取り出しに
     * 失敗したとみなしてその取り方は使わず(excludedに too_many で件数を残す)、次の取り方へ進む。
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
            'static_nav' => fn () => $this->firstLevelLinks($staticHtml, false),
            'rendered_nav' => fn () => $renderedHtml !== null ? $this->firstLevelLinks($renderedHtml, false) : [],
            'class_words' => fn () => $this->firstLevelLinks($renderedHtml ?? $staticHtml, true),
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
            // リンクの無い項目(ドロップダウンを開くボタン・「#」のリンク)は、名前だけを使う(リンク先による除外は見ない)。
            $hasUrl = trim((string) $link['href']) !== '';
            $url = $hasUrl ? $this->linkExtractor->resolveHref($baseUrl, (string) $link['href']) : null;

            $reason = null;
            if ($label === '' || ($hasUrl && $url === null)) {
                $reason = 'unresolved';
            } elseif ($url !== null && $this->isWithin($url, $scope)) {
                $reason = 'recruit_scope';
            } elseif ($label === $recruitLabel) {
                $reason = 'recruit_label';
            } elseif ($url !== null && $this->isSiteTop($url, $baseHost)) {
                $reason = 'site_top';
            } elseif ($url !== null && $sameHostOnly && $this->bareHost($url) !== $baseHost) {
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

        // 1段目の項目が多すぎるときは、サブメニューまで拾ったか、メニューではない場所を読んだ可能性が高い。この取り方は使わない。
        $max = (int) config('admin_comparison_pptx.corporate_top_menu_first_level_max');
        if ($max > 0 && count($labels) > $max) {
            $reasons['too_many'] = count($labels);

            return [];
        }

        return $labels;
    }

    /**
     * 依頼DA-2: メニューの「1段目の項目」だけを、文書順に取る。
     *  - 入れ物: nav取り(classWords=false)は header(main・article・footerの外)と nav(footerの外)のうち一番外側のもの。
     *    class取り(classWords=true)は、class / id にナビを表す語(config)を含む要素(main・article・footerの外)のうち一番外側のもの。
     *  - 入れ物の中の li のうち一番外側のもの(1段目の項目)からは、最初の「リンク(a[href])またはボタン(button)」だけを取る。
     *    その li の中の入れ子のリスト・パネル(サブメニュー)の中は取らない。ボタンの文字・リンクの無い「#」「javascript:」のリンクの文字は、
     *    リンク先なし(href='')の項目名として使う。
     *  - li の外にあるリンクは、そのまま1段目の項目として取る(リストでない作りのメニュー)。li の外のボタンは取らない。
     *
     * @return list<array{label: string, href: string}>
     */
    private function firstLevelLinks(string $html, bool $classWords): array
    {
        $xpath = $this->loadXpath($html);
        if ($xpath === null) {
            return [];
        }

        $containers = [];
        if ($classWords) {
            $words = array_values(array_filter(array_map('strtolower', (array) config('admin_comparison_pptx.corporate_top_menu_nav_words', [])), fn (string $w) => $w !== ''));
            foreach ($xpath->query('//*[@class or @id]') ?: [] as $element) {
                if ($element instanceof \DOMElement && $this->hasNavWord($element, $words) && ! $this->insideTag($element, ['main', 'article', 'footer'])) {
                    $containers[] = $element;
                }
            }
        } else {
            foreach ($xpath->query('//header[not(ancestor::footer) and not(ancestor::main) and not(ancestor::article)] | //nav[not(ancestor::footer)]') ?: [] as $element) {
                if ($element instanceof \DOMElement) {
                    $containers[] = $element;
                }
            }
        }
        $containers = $this->outermost($containers);

        $links = [];
        foreach ($containers as $container) {
            $seenLi = [];
            foreach ($xpath->query('.//a[@href] | .//button', $container) ?: [] as $node) {
                if (! $node instanceof \DOMElement) {
                    continue;
                }
                $topLi = $this->outermostListItem($node, $container);
                $isButton = strtolower($node->tagName) === 'button';
                if ($topLi === null && $isButton) {
                    continue;
                }
                if ($topLi !== null && isset($seenLi[$topLi->getNodePath()])) {
                    continue;
                }

                $label = $isButton ? $this->buttonLabel($node) : $this->labelOf($node);
                if ($label === null || preg_match('/[\p{L}\p{N}]/u', $label) !== 1) {
                    continue;
                }
                if ($topLi !== null) {
                    $seenLi[$topLi->getNodePath()] = true;
                }
                $href = $isButton ? '' : trim($node->getAttribute('href'));
                if ($href === '' || str_starts_with($href, '#') || stripos($href, 'javascript:') === 0) {
                    $href = '';
                }
                $links[] = ['label' => mb_substr($label, 0, 64), 'href' => $href];
            }
        }

        return $links;
    }

    /**
     * 要素のうち、ほかの要素の内側にないもの(入れ子の入れ物は一番外側だけ)。
     *
     * @param  list<\DOMElement>  $elements
     * @return list<\DOMElement>
     */
    private function outermost(array $elements): array
    {
        $ids = [];
        foreach ($elements as $element) {
            $ids[$element->getNodePath()] = true;
        }

        return array_values(array_filter($elements, function (\DOMElement $element) use ($ids): bool {
            for ($node = $element->parentNode; $node instanceof \DOMElement; $node = $node->parentNode) {
                if (isset($ids[$node->getNodePath()])) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** 入れ物($container)の内側で、$node を含む一番外側の li。無ければnull。 */
    private function outermostListItem(\DOMElement $node, \DOMElement $container): ?\DOMElement
    {
        $found = null;
        for ($parent = $node->parentNode; $parent instanceof \DOMElement && ! $parent->isSameNode($container); $parent = $parent->parentNode) {
            if (strtolower($parent->tagName) === 'li') {
                $found = $parent;
            }
        }

        return $found;
    }

    private function buttonLabel(\DOMElement $button): ?string
    {
        foreach ([$this->collapse($button->textContent), $this->collapse($button->getAttribute('aria-label')), $this->collapse($button->getAttribute('title'))] as $text) {
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $words
     */
    private function hasNavWord(\DOMElement $element, array $words): bool
    {
        $names = strtolower($element->getAttribute('class').' '.$element->getAttribute('id'));
        foreach ($words as $word) {
            if (str_contains($names, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tags
     */
    private function insideTag(\DOMElement $element, array $tags): bool
    {
        for ($node = $element; $node instanceof \DOMElement; $node = $node->parentNode) {
            if (in_array(strtolower($node->tagName), $tags, true)) {
                return true;
            }
        }

        return false;
    }

    private function loadXpath(string $html): ?\DOMXPath
    {
        if (trim($html) === '') {
            return null;
        }
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOENT | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($dom);
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
