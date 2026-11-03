<?php

namespace App\Services\CompanyProfile;

use App\Services\Analysis\HtmlSeoAnalyzer;
use App\Services\Analysis\SafeHttpFetcher;

/**
 * 依頼DK: AIが挙げた出典のURLを、サーバーが自分で取りに行く(SSRFの確認つきのSafeHttpFetcher)。HTML・テキストだけ
 * (PDFなどは中身を確かめられないので null)。本文の文字と<title>を返す。同じURLは1回だけ取りに行く。
 * 取りに行けなかったときは null(その事実は確かめられない=出さない)。本文はログに出さない。
 */
class CompanyProfileSourceFetcher
{
    /** @var array<string, ?array{title: string, text: string, final_url: string}> */
    private array $cache = [];

    private int $fetched = 0;

    public function __construct(
        private readonly SafeHttpFetcher $http,
        private readonly HtmlSeoAnalyzer $html,
    ) {}

    public function reset(): void
    {
        $this->cache = [];
        $this->fetched = 0;
    }

    /**
     * @return ?array{title: string, text: string, final_url: string}
     */
    public function fetch(string $url): ?array
    {
        $key = preg_replace('/#.*$/', '', trim($url));
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        if ($this->fetched >= (int) config('company_profile.source_fetch_max', 15)) {
            return null;
        }
        $this->fetched++;

        try {
            $result = $this->http->fetch($key, (array) config('company_profile.source_content_types'), (int) config('company_profile.source_fetch_timeout', 15));
        } catch (\Throwable) {
            return $this->cache[$key] = null;
        }
        if ($result->httpStatus < 200 || $result->httpStatus >= 300 || $result->body === '') {
            return $this->cache[$key] = null;
        }

        $isHtml = $result->contentType === null || ! str_starts_with(strtolower($result->contentType), 'text/plain');
        $title = '';
        if ($isHtml && preg_match('~<title[^>]*>(.*?)</title>~isu', $result->body, $m) === 1) {
            $title = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }
        $text = $isHtml ? $this->html->extractBodyText($result->body, excludeNavigation: false) : $result->body;

        return $this->cache[$key] = ['title' => $title, 'text' => (string) $text, 'final_url' => $result->finalUrl];
    }
}
