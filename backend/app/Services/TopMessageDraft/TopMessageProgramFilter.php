<?php

namespace App\Services\TopMessageDraft;

/**
 * 依頼CZ・CZ追補・CZ追補2・依頼DA: 制度の候補から、制度でない行を除く整理。素材ページ(既定。TopMessageDraftBuilder)と
 * パネルの材料(TopMessagePanelMaterialBuilder)が、この1か所を共通に使う(語・長さは config('top_message_draft'))。
 *
 * 除くもの(理由別に件数を数える):
 *  - job_page: 募集要項・求人一覧のページ(ページ名・パスの語)から取った候補
 *  - name_separator: 名前に区切り記号(「｜」「|」)を含む候補
 *  - no_excerpt: 抜粋が無い候補(名前だけの行)
 *  - recruit_category_name: 名前が採用区分の言葉と一致する候補(一致で判定)
 *  - generic_excerpt: 抜粋が「関連記事」などの汎用の見出しの言葉だけ
 *  - short_excerpt: 抜粋がconfigの文字数に満たない候補
 * 分類の見出し(kind=category)は触らない(その下の候補が残らなければ、並べる側が区切りの行にしない)。
 */
class TopMessageProgramFilter
{
    public const REASONS = ['job_page', 'name_separator', 'no_excerpt', 'recruit_category_name', 'generic_excerpt', 'short_excerpt'];

    /**
     * @param  list<array{url: string, source_title: string, items: list<array<string, mixed>>, dropped: array<string, int>}>  $perPage  TopMessageDraftExtractor::programItemsByPage の結果
     * @param  list<object>  $pages  perPageと同じ順のページ(url・titleを持つ)
     * @param  array<string, int>  $dropped  理由別の件数(参照で足す。REASONSのキーを用意しておく)
     * @return list<array{url: string, source_title: string, items: list<array<string, mixed>>, dropped: array<string, int>}> 制度でない候補を除いたもの
     */
    public function filter(array $perPage, array $pages, array &$dropped): array
    {
        $jobWords = array_values(array_filter(array_map(fn ($w) => mb_strtolower((string) $w), (array) config('top_message_draft.job_page_words')), fn (string $w) => $w !== ''));
        $generic = array_map(fn ($w) => $this->normalize((string) $w), (array) config('top_message_draft.generic_headings'));
        $related = array_map(fn ($w) => $this->normalize((string) $w), (array) config('top_message_draft.related_headings'));
        $separators = array_values(array_filter(array_map('strval', (array) config('top_message_draft.program_name_excluded_chars')), fn (string $c) => $c !== ''));
        $excerptMin = (int) config('top_message_draft.program_excerpt_min_chars');
        $recruitNames = array_values(array_filter(array_map(fn ($w) => $this->normalize((string) $w), (array) config('top_message_draft.program_name_excluded_words')), fn (string $w) => $w !== ''));

        foreach (self::REASONS as $reason) {
            $dropped[$reason] ??= 0;
        }

        foreach ($perPage as $p => $page) {
            $isJobPage = isset($pages[$p]) && $this->isJobPage((string) $pages[$p]->url, $pages[$p]->title, $jobWords);
            $kept = [];
            foreach ($page['items'] as $item) {
                if ($item['kind'] !== 'program') {
                    $kept[] = $item;

                    continue;
                }
                if ($isJobPage) {
                    $dropped['job_page']++;

                    continue;
                }
                foreach ($separators as $separator) {
                    if (str_contains($item['name'], $separator)) {
                        $dropped['name_separator']++;

                        continue 2;
                    }
                }
                $excerpt = trim((string) $item['excerpt']);
                if ($excerpt === '') {
                    $dropped['no_excerpt']++;

                    continue;
                }
                if (in_array($this->normalize($item['name']), $recruitNames, true)) {
                    $dropped['recruit_category_name']++;

                    continue;
                }
                $key = $this->normalize($excerpt);
                if (in_array($key, $generic, true) || in_array($key, $related, true)) {
                    $dropped['generic_excerpt']++;

                    continue;
                }
                if (mb_strlen($excerpt) < $excerptMin) {
                    $dropped['short_excerpt']++;

                    continue;
                }
                $kept[] = $item;
            }
            $perPage[$p]['items'] = $kept;
        }

        return $perPage;
    }

    /**
     * @param  list<string>  $words
     */
    private function isJobPage(string $url, ?string $title, array $words): bool
    {
        $haystack = mb_strtolower((string) $title.' '.(string) parse_url($url, PHP_URL_PATH));
        foreach ($words as $word) {
            if (str_contains($haystack, $word)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        return mb_strtolower((string) preg_replace('/[\s\p{P}\p{S}]+/u', '', $text));
    }
}
