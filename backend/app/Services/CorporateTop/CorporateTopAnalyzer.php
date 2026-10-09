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
        $labels = [];
        foreach ($this->htmlAnalyzer->extractMenuLinks($html) as $link) {
            $url = $this->linkExtractor->resolveHref($baseUrl, $link['href']);
            $label = trim((string) preg_replace('/\s+/u', ' ', (string) $link['label']));
            if ($url === null || $label === '' || ($url !== null && $this->isWithin($url, $scope)) || $label === $recruitLabel) {
                continue;
            }
            if (! in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
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
