<?php

namespace App\Services\TopMessageInsight;

use App\Enums\PageType;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\CrawlOriginScopeResolver;
use App\Services\Analysis\HtmlSeoAnalyzer;
use App\Services\Analysis\PageHtmlResolver;
use App\Services\TopMessageDraft\TopMessageDraftExtractor;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼CQ-1/CQ-2(AIは使わない): 巡回済みのページ(analysis_crawled_pages)と
 * 採用ページ・トップページの中から、トップメッセージのページを1つと、制度が
 * 書かれていそうなページを集める。
 *
 * 巡回の順や件数は変えない(読むだけ)。50件の中に無ければ「見つからない」でよい。
 * ブランド・ホイールの判定には一切影響させない。
 *
 * メッセージのページは、URL・<title>・<h1>のいずれかに
 * config('top_message_insight.message_page_keywords')の語を含むものを候補にする
 * (依頼CSA-5: そのページへのリンクの文字だけでは通さない)。制度のページは、URL・タイトルだけで見る。
 * 並びは、起点URL配下(CrawlOriginScopeResolver)を先に、次に本文の文字数が多い順。
 */
class TopMessagePageFinder
{
    public function __construct(
        private readonly PageHtmlResolver $htmlResolver,
        private readonly HtmlSeoAnalyzer $htmlSeoAnalyzer,
        private readonly CrawlOriginScopeResolver $originScopeResolver,
        private readonly TopMessageDraftExtractor $draftExtractor,
    ) {}

    public function find(WebsiteAnalysis $websiteAnalysis): TopMessageSelection
    {
        $pool = $this->collectPool($websiteAnalysis);
        $scope = $this->originScopeResolver->resolveScope($websiteAnalysis);

        $messageKeywords = $this->keywords('message_page_keywords');
        $primaryKeywords = $this->keywords('program_page_keywords_primary');
        $secondaryKeywords = $this->keywords('program_page_keywords_secondary');

        $messageKeywordMatches = [];
        $programTiers = [];

        foreach ($pool as $key => $entry) {
            $title = $entry['title'] ?? $this->readTitle($entry['model']);
            $pool[$key]['title'] = $title;

            $urlAndTitle = $entry['url'].' '.($title ?? '');

            // 依頼CSA-5: URL・<title>・<h1>のどれかに語があること。URL・タイトルで当たらないときだけ<h1>を見る。
            if ($this->containsAny($urlAndTitle, $messageKeywords)
                || $this->containsAny($this->firstHeading($this->readHtml($entry['model'])), $messageKeywords)) {
                $messageKeywordMatches[] = $key;
            }
            if ($this->containsAny($urlAndTitle, $primaryKeywords)) {
                $programTiers[$key] = 0;
            } elseif ($this->containsAny($urlAndTitle, $secondaryKeywords)) {
                $programTiers[$key] = 1;
            }
        }

        // CQA-1: 語に当たったページのうち、本文に代表者を示す語があり、インタビュー系でないものだけ。
        // 依頼CSA-5: 代表者を示す語は、ヘッダー・フッター・ナビの外(本文の中)にあること。
        $messageCandidates = [];
        foreach ($this->rank($pool, $messageKeywordMatches, $scope) as $page) {
            if ($page->text !== '' && $this->hasRepresentativeWord((string) $page->html) && ! $this->isInterviewPage($page->url, $page->title)) {
                $messageCandidates[] = $page;
            }
        }
        $message = $messageCandidates[0] ?? null;
        $messageUrlKey = $message !== null ? $this->urlKey($message->url) : null;
        // 依頼CS: メッセージのページが無いときも、日本語の制度のページだけを見る(英語のページを混ぜない)。
        $messageIsJapanese = $message === null || $this->japaneseRatio($message->text) >= (float) config('top_message_insight.japanese_ratio_threshold', 0.2);

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

    private function hasRepresentativeWord(string $html): bool
    {
        return $this->draftExtractor->hasWordOutsideChrome($html, array_map('strval', (array) config('top_message_insight.representative_words', [])));
    }

    /**
     * 最初の<h1>の文字(無ければ空)。保存済みのHTMLの文字列だけを見る。
     */
    private function firstHeading(?string $html): string
    {
        if ($html === null || preg_match('~<h1\b[^>]*>(.*?)</h1>~isu', $html, $m) !== 1) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
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
            // 依頼CS: 素材ページ(AIなし)がHTMLそのものを読めるよう、保存済みのHTMLも持たせる。
            $html = $this->readHtml($entry['model']);
            $text = $html === null ? '' : trim($this->htmlSeoAnalyzer->extractBodyText($html, excludeNavigation: true));
            $inScope = $scope === null || $this->originScopeResolver->isWithinScope($entry['url'], $entry['final_url'], $scope);
            $pages[] = [
                'order' => $order,
                'tier' => $tiers[$key] ?? 0,
                'page' => new TopMessagePage($entry['url'], $entry['title'], $text, $inScope, mb_strlen($text), $html),
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
