<?php

namespace App\Services\TopMessageInsight;

use App\Enums\PageType;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\CrawlLinkExtractor;
use App\Services\Analysis\CrawlOriginScopeResolver;
use App\Services\Analysis\HtmlSeoAnalyzer;
use App\Services\Analysis\PageHtmlResolver;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼CQ-1/CQ-2(AIは使わない): 巡回済みのページ(analysis_crawled_pages)と
 * 採用ページ・トップページの中から、トップメッセージのページを1つと、制度が
 * 書かれていそうなページを集める。
 *
 * 巡回の順や件数は変えない(読むだけ)。50件の中に無ければ「見つからない」でよい。
 * ブランド・ホイールの判定には一切影響させない。
 *
 * メッセージのページは、URL・タイトル・「そのページへのリンクの文字」のいずれかに
 * config('top_message_insight.message_page_keywords')の語を含むものを候補にする。
 * リンクの文字は、トップページ・採用ページ内のすべての<a>から取る(メニューに限らず
 * フッターや本文のリンクも含む)。制度のページは、URL・タイトルだけで見る。
 * 並びは、起点URL配下(CrawlOriginScopeResolver)を先に、次に本文の文字数が多い順。
 */
class TopMessagePageFinder
{
    private const LINK_LABEL_MAX_CHARS = 80;

    public function __construct(
        private readonly PageHtmlResolver $htmlResolver,
        private readonly HtmlSeoAnalyzer $htmlSeoAnalyzer,
        private readonly CrawlLinkExtractor $linkExtractor,
        private readonly CrawlOriginScopeResolver $originScopeResolver,
    ) {}

    public function find(WebsiteAnalysis $websiteAnalysis): TopMessageSelection
    {
        $pool = $this->collectPool($websiteAnalysis);
        $scope = $this->originScopeResolver->resolveScope($websiteAnalysis);
        $linkLabels = $this->collectLinkLabels($websiteAnalysis);

        $messageKeywords = $this->keywords('message_page_keywords');
        $primaryKeywords = $this->keywords('program_page_keywords_primary');
        $secondaryKeywords = $this->keywords('program_page_keywords_secondary');

        $messageKeywordMatches = [];
        $programTiers = [];

        foreach ($pool as $key => $entry) {
            $title = $entry['title'] ?? $this->readTitle($entry['model']);
            $pool[$key]['title'] = $title;

            $urlAndTitle = $entry['url'].' '.($title ?? '');
            $labels = implode(' ', $linkLabels[$this->urlKey($entry['url'])] ?? []);

            if ($this->containsAny($urlAndTitle.' '.$labels, $messageKeywords)) {
                $messageKeywordMatches[] = $key;
            }
            if ($this->containsAny($urlAndTitle, $primaryKeywords)) {
                $programTiers[$key] = 0;
            } elseif ($this->containsAny($urlAndTitle, $secondaryKeywords)) {
                $programTiers[$key] = 1;
            }
        }

        // CQA-1: 語に当たったページのうち、本文に代表者を示す語があり、インタビュー系でないものだけ。
        $messageCandidates = [];
        foreach ($this->rank($pool, $messageKeywordMatches, $scope) as $page) {
            if ($page->text !== '' && $this->hasRepresentativeWord($page->text) && ! $this->isInterviewPage($page->url, $page->title)) {
                $messageCandidates[] = $page;
            }
        }
        $message = $messageCandidates[0] ?? null;
        $messageUrlKey = $message !== null ? $this->urlKey($message->url) : null;
        $messageIsJapanese = $message !== null && $this->japaneseRatio($message->text) >= (float) config('top_message_insight.japanese_ratio_threshold', 0.2);

        // CQA-2: 一覧の続き・求人一覧・(メッセージが日本語なら)日本語でないページを除く。
        $programKeys = array_values(array_filter(
            array_keys($programTiers),
            fn (int $key) => $this->urlKey($pool[$key]['url']) !== $messageUrlKey
                && ! $this->matchesExcludedUrl($pool[$key]['url'])
                && ! ($messageIsJapanese && $this->hasNonJapaneseUrlSegment($pool[$key]['url'])),
        ));

        $programPages = [];
        $programCandidateCount = 0;
        foreach ($this->rank($pool, $programKeys, $scope, $programTiers) as $page) {
            if ($page->text === '' || ($messageIsJapanese && $this->japaneseRatio($page->text) < (float) config('top_message_insight.japanese_ratio_threshold', 0.2))) {
                continue;
            }
            $programCandidateCount++;
            $programPages[] = $page;
        }

        return new TopMessageSelection(
            message: $message,
            messageCandidateCount: count($messageCandidates),
            programPages: array_slice($programPages, 0, (int) config('top_message_insight.program_pages_max', 8)),
            programCandidateCount: $programCandidateCount,
            messageKeywordMatchCount: count($messageKeywordMatches),
        );
    }

    private function hasRepresentativeWord(string $text): bool
    {
        foreach ((array) config('top_message_insight.representative_words', []) as $word) {
            if ((string) $word !== '' && mb_stripos($text, (string) $word) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * URL・タイトルにインタビュー系の語がある。ただしタイトルに代表・社長・CEO(config)を含むものは除外しない。
     */
    private function isInterviewPage(string $url, ?string $title): bool
    {
        $title = (string) $title;

        foreach ((array) config('top_message_insight.interview_words', []) as $word) {
            if ((string) $word === '' || (mb_stripos($url, (string) $word) === false && mb_stripos($title, (string) $word) === false)) {
                continue;
            }
            foreach ((array) config('top_message_insight.interview_exception_title_words', []) as $keep) {
                if ((string) $keep !== '' && mb_stripos($title, (string) $keep) !== false) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function matchesExcludedUrl(string $url): bool
    {
        foreach ((array) config('top_message_insight.program_page_excluded_url_patterns', []) as $pattern) {
            if (@preg_match((string) $pattern, $url) === 1) {
                return true;
            }
        }

        return false;
    }

    private function hasNonJapaneseUrlSegment(string $url): bool
    {
        $segments = array_map('strtolower', explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/')));

        return array_intersect($segments, array_map('strtolower', (array) config('top_message_insight.non_japanese_url_segments', []))) !== [];
    }

    /**
     * 本文のうち、ひらがな・カタカナ・漢字の割合(空白を除く)。
     */
    private function japaneseRatio(string $text): float
    {
        $stripped = (string) preg_replace('/\s+/u', '', $text);
        $total = mb_strlen($stripped);
        if ($total === 0) {
            return 0.0;
        }

        return preg_match_all('/[\p{Hiragana}\p{Katakana}\p{Han}]/u', $stripped) / $total;
    }

    /**
     * @return array<int, array{url: string, title: ?string, model: AnalysisCrawledPage|AnalysisPage, final_url: ?string}>
     */
    private function collectPool(WebsiteAnalysis $websiteAnalysis): array
    {
        $pool = [];
        $seen = [];

        $add = function (AnalysisCrawledPage|AnalysisPage $page) use (&$pool, &$seen): void {
            $url = (string) ($page->final_url ?? $page->url);
            $key = $this->urlKey($url);

            if ($url === '' || isset($seen[$key]) || $page->raw_html_path === null) {
                return;
            }
            $seen[$key] = true;
            $pool[] = [
                'url' => $url,
                'title' => $page->title !== null && trim((string) $page->title) !== '' ? (string) $page->title : null,
                'model' => $page,
                'final_url' => $page->final_url,
            ];
        };

        foreach (AnalysisPage::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->whereIn('page_type', [PageType::Homepage, PageType::Recruit])
            ->orderBy('id')
            ->get() as $page) {
            $add($page);
        }

        foreach (AnalysisCrawledPage::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->where('status', AnalysisCrawledPage::STATUS_FETCHED)
            ->whereNotNull('raw_html_path')
            ->orderBy('depth')
            ->orderBy('id')
            ->get() as $page) {
            $add($page);
        }

        return $pool;
    }

    /**
     * トップページ・採用ページ内の<a>を、リンク先のURL(絶対URL化)ごとの文字の一覧にする。
     *
     * @return array<string, list<string>>
     */
    private function collectLinkLabels(WebsiteAnalysis $websiteAnalysis): array
    {
        $labels = [];

        foreach (AnalysisPage::query()
            ->where('website_analysis_id', $websiteAnalysis->id)
            ->whereIn('page_type', [PageType::Homepage, PageType::Recruit])
            ->get() as $page) {
            $html = $this->readHtml($page);
            if ($html === null) {
                continue;
            }
            $pageUrl = (string) ($page->final_url ?? $page->url);

            foreach ($this->extractLinks($html) as [$href, $label]) {
                $absolute = $this->linkExtractor->resolveHref($pageUrl, $href);
                if ($absolute === null) {
                    continue;
                }
                $key = $this->urlKey($absolute);
                if (! in_array($label, $labels[$key] ?? [], true)) {
                    $labels[$key][] = $label;
                }
            }
        }

        return $labels;
    }

    /**
     * @return list<array{0: string, 1: string}> [href, 文字]
     */
    private function extractLinks(string $html): array
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
            $label = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
            if ($label === '') {
                continue;
            }
            $links[] = [$node->getAttribute('href'), mb_substr($label, 0, self::LINK_LABEL_MAX_CHARS)];
        }

        return $links;
    }

    /**
     * 並びは、(制度のページのみ)優先の段 → 起点URL配下 → 本文の文字数が多い順(同じなら巡回の順)。
     *
     * @param  array<int, array<string, mixed>>  $pool
     * @param  list<int>  $candidates
     * @param  ?array{origin_url: string, host: string, path: string}  $scope
     * @param  array<int, int>  $tiers  プールのキー => 優先の段(小さいほど先)。省略時は全て同じ段
     * @return list<TopMessagePage>
     */
    private function rank(array $pool, array $candidates, ?array $scope, array $tiers = []): array
    {
        $pages = [];
        foreach ($candidates as $order => $key) {
            $entry = $pool[$key];
            $text = $this->readBodyText($entry['model']);
            $inScope = $scope === null || $this->originScopeResolver->isWithinScope($entry['url'], $entry['final_url'], $scope);
            $pages[] = [
                'order' => $order,
                'tier' => $tiers[$key] ?? 0,
                'page' => new TopMessagePage($entry['url'], $entry['title'], $text, $inScope, mb_strlen($text)),
            ];
        }

        usort($pages, fn (array $a, array $b) => ($a['tier'] <=> $b['tier'])
            ?: ($b['page']->inScope <=> $a['page']->inScope)
            ?: ($b['page']->fullTextLength <=> $a['page']->fullTextLength)
            ?: ($a['order'] <=> $b['order']));

        return array_map(fn (array $row) => $row['page'], $pages);
    }

    private function readHtml(AnalysisCrawledPage|AnalysisPage $page): ?string
    {
        $resolved = $this->htmlResolver->resolve($page);
        if ($resolved === null) {
            return null;
        }
        $html = Storage::disk('analysis')->get($resolved['path']);

        return is_string($html) && $html !== '' ? $html : null;
    }

    private function readBodyText(AnalysisCrawledPage|AnalysisPage $page): string
    {
        $html = $this->readHtml($page);

        return $html === null ? '' : trim($this->htmlSeoAnalyzer->extractBodyText($html, excludeNavigation: true));
    }

    private function readTitle(AnalysisCrawledPage|AnalysisPage $page): ?string
    {
        $html = $this->readHtml($page);
        $title = $html === null ? null : $this->htmlSeoAnalyzer->extractPageTitle($html);

        return $title !== null && trim($title) !== '' ? trim($title) : null;
    }

    /**
     * @return list<string>
     */
    private function keywords(string $configKey): array
    {
        return array_values(array_filter(
            array_map(fn ($k) => mb_strtolower(trim((string) $k)), (array) config("top_message_insight.{$configKey}", [])),
            fn (string $k) => $k !== '',
        ));
    }

    /**
     * @param  list<string>  $keywords  小文字化済み
     */
    private function containsAny(string $haystack, array $keywords): bool
    {
        $haystack = mb_strtolower($haystack);
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    public function urlKey(string $url): string
    {
        return TopMessagePage::keyOf($url);
    }
}
