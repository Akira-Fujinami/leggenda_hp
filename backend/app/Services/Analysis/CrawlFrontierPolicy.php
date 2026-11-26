<?php

namespace App\Services\Analysis;

/**
 * 依頼DF(2026-10-22): 巡回で「どのリンクを回るか・どれを先に回るか」の判定。
 *
 * 本番のサイボウズ(analysis_id=175)で、50ページの枠を社員紹介(/recruit/staff/ 配下17ページ)や
 * 採用の個人情報の通知(Recruitment Privacy Notice)が使い、制度のページが枠に入らなかった。
 * これを受けて次の3つを足す(いずれも config で切り替え、一覧は config だけに置く):
 *
 *  - DF-1 優先: URLのパスかリンクの文字に制度を表す言葉(crawl_priority_words)を含むリンクを先に回る
 *  - DF-2 上限: 同じ親ディレクトリの下のページは、取得済みが crawl_same_parent_soft_cap 件に達したら後回し
 *             (捨てない。他のリンクを回り終えて枠が残っていれば回る)
 *  - DF-3 除外: URLのパスかリンクの文字が crawl_skip_words に当たるリンク(個人情報・規約など)は回らない
 *
 * 判定はURLとリンクの文字だけで決まる純粋な関数(DB・時刻・乱数を使わない)。
 */
class CrawlFrontierPolicy
{
    /**
     * DF-3: 回らないリンクか。URLのパス(デコード後)かリンクの文字が除外の言葉を含む。
     */
    public function shouldSkip(string $url, ?string $linkText = null): bool
    {
        if (! (bool) config('brand_wheel.crawl_skip_enabled', true)) {
            return false;
        }

        if ($this->containsAny($this->decodedPath($url), $linkText, (array) config('brand_wheel.crawl_skip_words', []))) {
            return true;
        }

        // 依頼DX: フォームのページ(パスの区切りが login・inquiry・password などで始まる)。
        $prefixes = $this->normalizedList((array) config('brand_wheel.crawl_skip_segment_prefixes', []));
        foreach ($this->segments($url) as $segment) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($segment, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * DF-1: 先に回るリンクか。URLのパス(デコード後)かリンクの文字が制度の言葉を含む。
     */
    public function isPriority(string $url, ?string $linkText = null): bool
    {
        if (! (bool) config('brand_wheel.crawl_priority_enabled', true)) {
            return false;
        }

        return $this->containsAny($this->decodedPath($url), $linkText, (array) config('brand_wheel.crawl_priority_words', []));
    }

    /**
     * 依頼DS-2a: 外国語のページか。URLのパスの区切りのどれかが外国語の区切り(/en/・/e/ など)に一致する。
     * 起点URLのパスにある区切りは数えない(英語の採用ページを入力したときは、その配下を回る)。
     */
    public function isForeignLanguage(string $url, ?string $originUrl = null): bool
    {
        if (! (bool) config('brand_wheel.crawl_foreign_language_skip_enabled', true)) {
            return false;
        }

        $languages = $this->normalizedList((array) config('brand_wheel.crawl_foreign_language_segments', []));
        $originSegments = $originUrl === null ? [] : $this->segments($originUrl);
        foreach ($this->segments($url) as $segment) {
            if (in_array($segment, $languages, true) && ! in_array($segment, $originSegments, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 依頼DS-2b: 後回しにするページ(ニュース・記事・製品など)か。URLのパスの区切りのどれかが一覧に一致する。
     * 配下の外にだけ当てるかどうかは呼び出し側(CrawlWebsitePageJob::selectNextPendingPage())が決める。
     */
    public function isDeprioritized(string $url): bool
    {
        if (! (bool) config('brand_wheel.crawl_deprioritize_enabled', true)) {
            return false;
        }

        $words = $this->normalizedList((array) config('brand_wheel.crawl_deprioritized_segments', []));

        return array_intersect($this->segments($url), $words) !== [];
    }

    /**
     * 依頼DS-2c: 配下の外から取るページの数の上限。0以下なら上限なし。
     */
    public function outsideOriginMaxPages(): int
    {
        return (int) config('brand_wheel.crawl_outside_origin_max_pages', 20);
    }

    /**
     * DF-2: 上限を数える単位(親ディレクトリ)。ホスト+パスの最後の区切りまで。
     * パスが「/」で終わるページ(ディレクトリの入口)はそのディレクトリ自身、
     * それ以外は最後の区切りまで。クエリ(?page=2 など)は無視する ―― 一覧の
     * 2ページ目以降も同じ親として数える。
     *
     * 例: /recruit/staff/akai.html → /recruit/staff/、/recruit/staff/ → /recruit/staff/、
     *     /recruit/career/?page=2 → /recruit/career/
     */
    public function parentKey(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        if ($path === '') {
            $path = '/';
        }

        if (! str_ends_with($path, '/')) {
            $slash = strrpos($path, '/');
            $path = $slash === false ? '/' : substr($path, 0, $slash + 1);
        }

        return $host.$path;
    }

    /**
     * DF-2 の上限(件数)。0以下なら上限なし。
     */
    public function sameParentSoftCap(): int
    {
        return (int) config('brand_wheel.crawl_same_parent_soft_cap', 5);
    }

    /**
     * 抽出したリンク(url => リンクの文字)から、回らないものを除き、先に回るものを前へ並べる
     * (同じ優先度の中では元の順番を保つ)。enqueue()はこの順に行を作るため、同じ深さの
     * 中では先に回るリンクほど id が小さくなる。
     *
     * 依頼DS-2a: 外国語のページ(isForeignLanguage())も除く。$originUrl を渡すと、そのパスにある区切りは除外の根拠にしない。
     *
     * @param  array<string, string>  $links
     * @return array{links: list<string>, skipped: int, prioritized: int}
     */
    public function orderLinks(array $links, ?string $originUrl = null): array
    {
        $priority = [];
        $normal = [];
        $skipped = 0;

        foreach ($links as $url => $text) {
            $url = (string) $url;
            // 依頼DS-2a: 外国語のページも、回らないリンクとして数える。
            if ($this->shouldSkip($url, $text) || $this->isForeignLanguage($url, $originUrl)) {
                $skipped++;

                continue;
            }

            if ($this->isPriority($url, $text)) {
                $priority[] = $url;
            } else {
                $normal[] = $url;
            }
        }

        return ['links' => [...$priority, ...$normal], 'skipped' => $skipped, 'prioritized' => count($priority)];
    }

    /**
     * URLのパス(デコード後)の区切り。小文字にし、「_」は「-」にそろえる(en_US → en-us)。
     *
     * @return list<string>
     */
    private function segments(string $url): array
    {
        $parts = explode('/', mb_strtolower($this->decodedPath($url)));

        return array_values(array_filter(array_map(fn (string $p) => str_replace('_', '-', trim($p)), $parts), fn (string $p) => $p !== ''));
    }

    /**
     * @param  list<mixed>  $words
     * @return list<string>
     */
    private function normalizedList(array $words): array
    {
        return array_values(array_filter(array_map(fn ($w) => str_replace('_', '-', mb_strtolower(trim((string) $w))), $words), fn (string $w) => $w !== ''));
    }

    private function decodedPath(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return rawurldecode($path);
    }

    /**
     * @param  list<mixed>  $words
     */
    private function containsAny(string $path, ?string $linkText, array $words): bool
    {
        $haystacks = [mb_strtolower($path)];
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $linkText));
        if ($text !== '') {
            $haystacks[] = mb_strtolower($text);
        }

        foreach ($words as $word) {
            $needle = mb_strtolower(trim((string) $word));
            if ($needle === '') {
                continue;
            }
            foreach ($haystacks as $haystack) {
                if ($haystack !== '' && mb_strpos($haystack, $needle) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}
