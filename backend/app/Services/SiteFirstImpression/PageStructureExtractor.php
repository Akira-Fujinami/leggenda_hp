<?php

namespace App\Services\SiteFirstImpression;

use App\Services\Analysis\CrawlLinkExtractor;

/**
 * 依頼CX-1: ページのHTMLから、求職者が最初に目にする構造を、上から順に取り出す(通信しない・AIを使わない。同じ入力から必ず同じ結果)。
 * 描画済みのHTML(無ければ静的なHTML)を渡す。各要素にIDを振る(例: corp-nav-3、corp-sec-2-item-4)。IDの接頭辞は呼び出し側が決める
 * (2ページを同じ文脈でAIに渡すため、ページごとに変える)。
 *
 *  - グローバルナビ: header / nav の中のリンクの文字(順番つき)。footerの中のnavは含めない
 *  - メインビジュアル: 最初に見つかったメインビジュアルらしい要素(class/idの語はconfig)の中の、画像のalt・見出し・文字。
 *    見つからなければ、最初のh1と、最初のalt付き画像
 *  - セクション: 見出し(h1〜h3)ごとに、その下の項目(リンクの文字・h4〜h6の見出し)を順番つきで。セクションごとの項目数(item_count)つき
 *  - フッター: footerの中のリンクの文字(まとめて1つ)
 *  - 採用への導線: 採用ページ(起点)へのリンクが、グローバルナビ/セクション内/フッターのどこにあるか(無ければ none)
 *
 * 「上から何%の位置にあるか」は、描画しないと分からないため、ここでは出さない(position_percent は null)。
 * 閾値・語はすべて config('site_first_impression.*')。
 */
class PageStructureExtractor
{
    public function __construct(private readonly CrawlLinkExtractor $links = new CrawlLinkExtractor) {}

    /**
     * @param  ?string  $recruitUrl  採用ページ(起点)のURL。コーポレートTOPのときだけ渡す(採用への導線を探す)
     * @return array<string, mixed>
     *   nav / hero / sections / footer / recruit_link / counts / elements(id => 文字。確認用) / numbers(構造の数字。確認用)
     */
    public function extract(string $html, string $baseUrl, string $prefix, ?string $recruitUrl = null): array
    {
        $dom = $this->load($html);
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//script|//style|//noscript|//template|//svg|//iframe') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $maxChars = (int) config('site_first_impression.element_text_max_chars', 80);

        // --- グローバルナビ
        $nav = [];
        $navHrefs = [];
        $seen = [];
        foreach ($xpath->query('(//header//a | //nav//a)') ?: [] as $a) {
            if (! $a instanceof \DOMElement || $this->inside($a, ['footer'])) {
                continue;
            }
            $label = $this->cut($this->labelOf($a), $maxChars);
            $key = $label.'|'.$a->getAttribute('href');
            if ($label === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $index = count($nav) + 1;
            if ($index > (int) config('site_first_impression.nav_max_items', 30)) {
                break;
            }
            $nav[] = ['id' => "{$prefix}-nav-{$index}", 'text' => $label];
            $navHrefs[$index] = $a->getAttribute('href');
        }

        // --- メインビジュアル
        $hero = $this->hero($xpath, $prefix, $maxChars);
        $heroNodes = $hero['nodes'];
        $hero = $hero['items'];

        // --- セクション
        $sections = [];
        $sectionHrefs = [];
        $current = null;
        $nodes = $xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6 or self::a]') ?: [];
        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement || $this->inside($node, ['header', 'nav', 'footer']) || $this->insideAny($node, $heroNodes)) {
                continue;
            }
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['h1', 'h2', 'h3'], true)) {
                $heading = $this->cut($this->normalize($node->textContent), $maxChars);
                if ($heading === '' || count($sections) >= (int) config('site_first_impression.sections_max', 20)) {
                    $current = null;
                    if (count($sections) >= (int) config('site_first_impression.sections_max', 20)) {
                        break;
                    }

                    continue;
                }
                $n = count($sections) + 1;
                $sections[] = ['id' => "{$prefix}-sec-{$n}", 'heading' => $heading, 'level' => (int) substr($tag, 1), 'item_count' => 0, 'items' => [], 'position_percent' => null];
                $current = $n - 1;
                $sectionHrefs[$current] = [];

                continue;
            }

            // h4〜h6・リンク = 項目。見出しより前にあるものは、見出しの無いセクション(ページ冒頭)にまとめる。
            $label = $this->cut($tag === 'a' ? $this->labelOf($node) : $this->normalize($node->textContent), $maxChars);
            if ($label === '') {
                continue;
            }
            if ($current === null) {
                if (count($sections) >= (int) config('site_first_impression.sections_max', 20)) {
                    continue;
                }
                $n = count($sections) + 1;
                $sections[] = ['id' => "{$prefix}-sec-{$n}", 'heading' => '', 'level' => 0, 'item_count' => 0, 'items' => [], 'position_percent' => null];
                $current = $n - 1;
                $sectionHrefs[$current] = [];
            }
            $last = end($sections[$current]['items']);
            if ($last !== false && $last['text'] === $label) {
                continue;   // 見出し(h4)とそのリンクが同じ文字のときの重複
            }
            $sections[$current]['item_count']++;
            if (count($sections[$current]['items']) < (int) config('site_first_impression.section_items_max', 12)) {
                $m = count($sections[$current]['items']) + 1;
                $sections[$current]['items'][] = ['id' => $sections[$current]['id']."-item-{$m}", 'text' => $label];
                $sectionHrefs[$current][$m] = $tag === 'a' ? $node->getAttribute('href') : '';
            }
        }
        $sections = array_values(array_filter($sections, fn (array $s) => $s['heading'] !== '' || $s['item_count'] > 0));

        // --- フッター
        $footerLabels = [];
        $footerHrefs = [];
        foreach ($xpath->query('//footer//a') ?: [] as $a) {
            if (! $a instanceof \DOMElement) {
                continue;
            }
            $label = $this->cut($this->labelOf($a), $maxChars);
            if ($label !== '' && ! in_array($label, $footerLabels, true)) {
                $footerLabels[] = $label;
                $footerHrefs[] = $a->getAttribute('href');
            }
        }
        $footer = $footerLabels === [] ? null : ['id' => "{$prefix}-footer", 'text' => $this->cut(implode(' / ', $footerLabels), (int) config('site_first_impression.footer_max_chars', 600))];

        // --- 採用への導線
        $recruitLink = $recruitUrl === null ? null : $this->recruitLink($baseUrl, $recruitUrl, $nav, $navHrefs, $sections, $sectionHrefs, $footer, $footerHrefs);

        // --- 確認用: 要素のIDと文字・構造の数字
        $elements = [];
        foreach ($nav as $item) {
            $elements[$item['id']] = $item['text'];
        }
        foreach ($hero as $item) {
            $elements[$item['id']] = $item['text'];
        }
        foreach ($sections as $section) {
            $elements[$section['id']] = $section['heading'];
            foreach ($section['items'] as $item) {
                $elements[$item['id']] = $item['text'];
            }
        }
        if ($footer !== null) {
            $elements[$footer['id']] = $footer['text'];
        }

        $numbers = [count($nav), count($hero), count($sections)];
        foreach (array_keys($elements) as $id) {
            preg_match_all('/\d+/', $id, $m);
            array_push($numbers, ...array_map('intval', $m[0]));
        }
        foreach ($sections as $section) {
            $numbers[] = (int) $section['item_count'];
        }
        if (is_array($recruitLink) && isset($recruitLink['ordinal'])) {
            $numbers[] = (int) $recruitLink['ordinal'];
        }

        return [
            'prefix' => $prefix,
            'nav' => $nav,
            'hero' => $hero,
            'sections' => $sections,
            'footer' => $footer,
            'recruit_link' => $recruitLink,
            'counts' => ['nav' => count($nav), 'hero' => count($hero), 'sections' => count($sections), 'items' => array_sum(array_column($sections, 'item_count'))],
            'elements' => $elements,
            'numbers' => array_values(array_unique($numbers)),
        ];
    }

    /**
     * @return array{items: list<array{id: string, kind: string, text: string}>, nodes: list<\DOMElement>}
     */
    private function hero(\DOMXPath $xpath, string $prefix, int $maxChars): array
    {
        $container = null;
        foreach ($xpath->query('//body//*[@class or @id]') ?: [] as $element) {
            if (! $element instanceof \DOMElement || $this->inside($element, ['header', 'nav', 'footer'])) {
                continue;
            }
            $names = strtolower($element->getAttribute('class').' '.$element->getAttribute('id'));
            foreach ((array) config('site_first_impression.hero_keywords', []) as $word) {
                if (preg_match('/(^|[\s_-])'.preg_quote((string) $word, '/').'($|[\s_-])/', $names) === 1) {
                    $container = $element;

                    break 2;
                }
            }
        }

        $items = [];
        $nodes = [];
        $add = function (string $kind, string $text) use (&$items, $prefix, $maxChars): void {
            $text = $this->cut($text, $maxChars);
            if ($text === '' || count($items) >= (int) config('site_first_impression.hero_max_items', 8)) {
                return;
            }
            foreach ($items as $item) {
                if ($item['text'] === $text) {
                    return;
                }
            }
            $items[] = ['id' => $prefix.'-hero-'.(count($items) + 1), 'kind' => $kind, 'text' => $text];
        };

        if ($container !== null) {
            $nodes[] = $container;
            $sub = new \DOMXPath($container->ownerDocument);
            foreach ($sub->query('.//*[self::img or self::h1 or self::h2 or self::h3 or self::h4 or self::p]', $container) ?: [] as $el) {
                if (! $el instanceof \DOMElement) {
                    continue;
                }
                $tag = strtolower($el->tagName);
                $tag === 'img' ? $add('alt', $this->normalize($el->getAttribute('alt'))) : $add($tag === 'p' ? 'text' : 'heading', $this->normalize($el->textContent));
            }
        } else {
            foreach ($xpath->query('//h1') ?: [] as $h1) {
                if ($h1 instanceof \DOMElement && ! $this->inside($h1, ['header', 'nav', 'footer'])) {
                    $add('heading', $this->normalize($h1->textContent));
                    $nodes[] = $h1;

                    break;
                }
            }
            foreach ($xpath->query('//img[@alt]') ?: [] as $img) {
                if ($img instanceof \DOMElement && ! $this->inside($img, ['header', 'nav', 'footer'])) {
                    $text = $this->normalize($img->getAttribute('alt'));
                    if ($text !== '') {
                        $add('alt', $text);
                        $nodes[] = $img;
                        if (count($items) >= 3) {
                            break;
                        }
                    }
                }
            }
        }

        return ['items' => $items, 'nodes' => $nodes];
    }

    /**
     * @param  list<array{id: string, text: string}>  $nav
     * @param  array<int, string>  $navHrefs
     * @param  list<array<string, mixed>>  $sections
     * @param  array<int, array<int, string>>  $sectionHrefs
     * @param  ?array{id: string, text: string}  $footer
     * @param  list<string>  $footerHrefs
     * @return array{where: string, id: ?string, ordinal: ?int}
     */
    private function recruitLink(string $baseUrl, string $recruitUrl, array $nav, array $navHrefs, array $sections, array $sectionHrefs, ?array $footer, array $footerHrefs): array
    {
        $recruit = parse_url($recruitUrl);
        $host = strtolower((string) ($recruit['host'] ?? ''));
        $path = trim((string) ($recruit['path'] ?? ''), '/');

        $matches = function (string $href) use ($baseUrl, $host, $path): bool {
            $url = $this->links->resolveHref($baseUrl, $href);
            if ($url === null) {
                return false;
            }
            $parts = parse_url($url);
            if (strtolower((string) ($parts['host'] ?? '')) !== $host) {
                return false;
            }
            $candidate = trim((string) ($parts['path'] ?? ''), '/');

            return $path === '' ? $candidate === '' : ($candidate === $path || str_starts_with($candidate, $path.'/'));
        };

        foreach ($nav as $i => $item) {
            if ($matches((string) ($navHrefs[$i + 1] ?? ''))) {
                return ['where' => 'nav', 'id' => $item['id'], 'ordinal' => $i + 1];
            }
        }
        foreach ($sections as $s => $section) {
            foreach ($section['items'] as $m => $item) {
                if ($matches((string) ($sectionHrefs[$s][$m + 1] ?? ''))) {
                    return ['where' => 'section', 'id' => $item['id'], 'ordinal' => $m + 1];
                }
            }
        }
        foreach ($footerHrefs as $href) {
            if ($footer !== null && $matches((string) $href)) {
                return ['where' => 'footer', 'id' => $footer['id'], 'ordinal' => null];
            }
        }

        return ['where' => 'none', 'id' => null, 'ordinal' => null];
    }

    private function load(string $html): \DOMDocument
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // charset宣言の無いHTMLをISO-8859-1として読まないよう、UTF-8を明示する(HtmlSeoAnalyzerと同じ考え方)。
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    /** リンクの文字: 見えている文字 → <img>のalt → aria-label → title属性。 */
    private function labelOf(\DOMElement $a): string
    {
        $text = $this->normalize($a->textContent);
        if ($text !== '') {
            return $text;
        }
        foreach ($a->getElementsByTagName('img') as $img) {
            $alt = $this->normalize($img->getAttribute('alt'));
            if ($alt !== '') {
                return $alt;
            }
        }

        return $this->normalize($a->getAttribute('aria-label')) ?: $this->normalize($a->getAttribute('title'));
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $text)));
    }

    private function cut(string $text, int $max): string
    {
        return $max > 0 && mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max)) : $text;
    }

    /**
     * @param  list<string>  $tags
     */
    private function inside(\DOMNode $node, array $tags): bool
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent instanceof \DOMElement && in_array(strtolower($parent->tagName), $tags, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<\DOMElement>  $containers
     */
    private function insideAny(\DOMNode $node, array $containers): bool
    {
        foreach ($containers as $container) {
            for ($n = $node; $n !== null; $n = $n->parentNode) {
                if ($n === $container) {
                    return true;
                }
            }
        }

        return false;
    }
}
