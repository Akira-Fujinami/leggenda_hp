<?php

namespace App\Services\TopMessageInsight;

use App\Services\BrandWheel\BrandWheelTextTruncator;

/**
 * 依頼CQ-3「サーバー側の確認」(ここが本体): AIの出力をそのまま使わず、AIへ渡した
 * 本文(TopMessagePage::$text)と突き合わせて、合わないものを捨てる。
 *
 *  1. quote  : メッセージのページの本文にそのまま含まれること(空白・改行の違いだけは
 *              無視)。長さの上限(config)。満たさなければページを作らない。
 *  2. keyword: メッセージのページの本文に含まれること。含まれなければキーワードごと捨てる。
 *  3. evidence: source_urlのページの本文に含まれること。source_urlが入力に渡したページで
 *              なければ捨てる。
 *  4. name   : evidenceに含まれること(全角・半角、空白、記号、大文字・小文字の違いだけはそろえて比べる)。
 *  7. category: config('top_message_insight.program_categories')のどれか1つ。無ければ捨てる(依頼CQ追補 CQA-3)。
 *  8. source_url: メッセージのページではなく、制度のページ(CQ-2で集めたもの)であること。
 *              メッセージのページや、その他のページから取った制度は捨てる(依頼CQ追補 CQA-3)。
 *  5. detail : 数字(0-9の連なり、小数点・桁区切りを含む)がすべてevidenceに含まれること。
 *              1つでも無ければその制度を捨てる。
 *  6. detail の長さの上限(config)。超えたら既存の切り詰め処理で切る。
 *
 * 捨てた結果、キーワードが最小数未満、または制度の合計が最小数未満なら、ページを作らない。
 * 捨てた理由は件数だけを返す(本文・メッセージの文章は含めない)。
 *
 * 確認を緩めないこと。結果を良く見せるための例外を作らない。
 */
class TopMessageInsightVerifier
{
    /**
     * @param  array<string, mixed>  $output  AIの出力(JSONをデコードしたもの)
     * @param  list<TopMessagePage>  $programPages
     * @return array{
     *     ok: bool,
     *     reason: ?string,
     *     quote: ?string,
     *     keywords: list<array{keyword: string, programs: list<array{name: string, category: string, detail: string, source_url: string, source_title: ?string}>}>,
     *     discarded: array<string, int>,
     * }
     */
    public function verify(array $output, TopMessagePage $messagePage, array $programPages): array
    {
        $discarded = [];
        $count = function (string $reason) use (&$discarded): void {
            $discarded[$reason] = ($discarded[$reason] ?? 0) + 1;
        };

        $messageText = $this->normalize($messagePage->text);

        // 根拠の出どころにしてよいのは、制度のページ(CQ-2で集めたもの)だけ。メッセージのページは含めない。
        $pages = [];
        foreach ($programPages as $page) {
            $pages[TopMessagePage::keyOf($page->url)] = ['page' => $page, 'text' => $this->normalize($page->text)];
        }
        $messageKey = TopMessagePage::keyOf($messagePage->url);

        // 1. quote
        $quote = $output['quote'] ?? null;
        $quote = is_string($quote) ? trim($quote) : '';
        if ($quote === '') {
            $count('quote_missing');

            return $this->fail('quote_invalid', $discarded);
        }
        if (! str_contains($messageText, $this->normalize($quote))) {
            $count('quote_not_in_page');

            return $this->fail('quote_invalid', $discarded);
        }
        if (mb_strlen($quote) > (int) config('top_message_insight.quote_max_chars', 60)) {
            $count('quote_too_long');

            return $this->fail('quote_invalid', $discarded);
        }

        $keywordsMax = (int) config('top_message_insight.keywords_max', 4);
        $programsMax = (int) config('top_message_insight.programs_per_keyword_max', 3);
        $detailMax = (int) config('top_message_insight.detail_max_chars', 30);

        $keywords = [];
        $seenKeywords = [];
        $seenPrograms = [];

        foreach ((array) ($output['keywords'] ?? []) as $rawKeyword) {
            $keyword = is_array($rawKeyword) && is_string($rawKeyword['keyword'] ?? null) ? trim($rawKeyword['keyword']) : '';

            // 2. keyword
            if ($keyword === '') {
                $count('keyword_invalid');

                continue;
            }
            $keywordKey = $this->normalize($keyword);
            if (! str_contains($messageText, $keywordKey)) {
                $count('keyword_not_in_message');

                continue;
            }
            if (isset($seenKeywords[$keywordKey])) {
                $count('keyword_duplicate');

                continue;
            }
            if (count($keywords) >= $keywordsMax) {
                $count('keywords_over_limit');

                continue;
            }

            $programs = [];
            foreach ((array) ($rawKeyword['programs'] ?? []) as $rawProgram) {
                $program = $this->verifyProgram($rawProgram, $pages, $messageKey, $detailMax, $count);
                if ($program === null) {
                    continue;
                }

                $programKey = $this->normalize($program['name']);
                if (isset($seenPrograms[$programKey])) {
                    $count('program_duplicate');

                    continue;
                }
                if (count($programs) >= $programsMax) {
                    $count('programs_over_limit');

                    continue;
                }

                $seenPrograms[$programKey] = true;
                $programs[] = $program;
            }

            if ($programs === []) {
                $count('keyword_without_programs');

                continue;
            }

            $seenKeywords[$keywordKey] = true;
            $keywords[] = ['keyword' => $keyword, 'programs' => $programs];
        }

        $totalPrograms = array_sum(array_map(fn (array $k) => count($k['programs']), $keywords));

        if (count($keywords) < (int) config('top_message_insight.min_keywords', 2)) {
            return $this->fail('too_few_keywords', $discarded, $quote, $keywords);
        }
        if ($totalPrograms < (int) config('top_message_insight.min_programs_total', 3)) {
            return $this->fail('too_few_programs', $discarded, $quote, $keywords);
        }

        return ['ok' => true, 'reason' => null, 'quote' => $quote, 'keywords' => $keywords, 'discarded' => $discarded];
    }

    /**
     * @param  array<string, array{page: TopMessagePage, text: string}>  $pages  制度のページだけ
     * @param  callable(string): void  $count
     * @return ?array{name: string, category: string, detail: string, source_url: string, source_title: ?string}
     */
    private function verifyProgram(mixed $raw, array $pages, string $messageKey, int $detailMax, callable $count): ?array
    {
        if (! is_array($raw)) {
            $count('program_invalid');

            return null;
        }

        $name = is_string($raw['name'] ?? null) ? trim($raw['name']) : '';
        $detail = is_string($raw['detail'] ?? null) ? trim($raw['detail']) : '';
        $sourceUrl = is_string($raw['source_url'] ?? null) ? trim($raw['source_url']) : '';
        $evidence = is_string($raw['evidence'] ?? null) ? trim($raw['evidence']) : '';

        if ($name === '' || $evidence === '' || $sourceUrl === '') {
            $count('program_invalid');

            return null;
        }

        // 7. category は一覧のどれか1つ。
        $category = is_string($raw['category'] ?? null) ? trim($raw['category']) : '';
        if (! in_array($category, (array) config('top_message_insight.program_categories', []), true)) {
            $count('category_invalid');

            return null;
        }

        // 8. source_url は制度のページ。メッセージのページ・入力に無いページから取ったものは捨てる。
        // 3. evidence は source_url のページの本文に含まれる。
        $sourceKey = TopMessagePage::keyOf($sourceUrl);
        if ($sourceKey === $messageKey) {
            $count('source_is_message_page');

            return null;
        }
        $source = $pages[$sourceKey] ?? null;
        if ($source === null) {
            $count('source_url_not_in_input');

            return null;
        }
        $evidenceKey = $this->normalize($evidence);
        if (! str_contains($source['text'], $evidenceKey)) {
            $count('evidence_not_in_page');

            return null;
        }

        // 4. name は evidence に含まれる(表記の揺れだけはそろえて比べる)。
        $nameLoose = $this->normalizeLoosely($name);
        if ($nameLoose === '' || ! str_contains($this->normalizeLoosely($evidence), $nameLoose)) {
            $count('name_not_in_evidence');

            return null;
        }

        // 5. detail の数字はすべて evidence に含まれる。
        $evidenceNumbers = $this->numbers($evidence);
        if (array_diff($this->numbers($detail), $evidenceNumbers) !== []) {
            $count('number_not_in_evidence');

            return null;
        }

        // 6. detail の長さ。切った結果、数字の途中で切れて別の数字になった場合は
        //    説明を付けない(名前は確認済みなので制度そのものは残す)。
        if (mb_strlen($detail) > $detailMax) {
            $detail = BrandWheelTextTruncator::truncateAtSentenceBoundary($detail, $detailMax);
            if (array_diff($this->numbers($detail), $evidenceNumbers) !== []) {
                $detail = '';
                $count('detail_dropped_after_cut');
            }
        }

        return [
            'name' => $name,
            'category' => $category,
            'detail' => $detail,
            'source_url' => $source['page']->url,
            'source_title' => $source['page']->title,
        ];
    }

    /**
     * 空白(全角を含む)を取り除く。空白・改行の違いだけを無視するための比較用の形。
     */
    private function normalize(string $text): string
    {
        return (string) preg_replace('/[\s\x{3000}\x{00A0}\x{200B}\x{FEFF}]+/u', '', $text);
    }

    /**
     * 制度の名前とevidenceを比べるときだけ使う、表記の揺れをそろえた形: 全角・半角(英数・記号・
     * カナ)、空白、記号・約物、大文字・小文字の違いを無視する。言い換え・省略は吸収しない。
     */
    private function normalizeLoosely(string $text): string
    {
        return (string) preg_replace('/[\s\x{3000}\p{P}\p{S}]+/u', '', mb_strtolower(mb_convert_kana($text, 'asKV')));
    }

    /**
     * 数字のまとまり(小数点・桁区切りでつながるもの)の一覧。全角の数字は半角にそろえる。
     *
     * @return list<string>
     */
    private function numbers(string $text): array
    {
        preg_match_all('/[0-9]+(?:[.,][0-9]+)*/', mb_convert_kana($text, 'n'), $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * @param  array<string, int>  $discarded
     * @param  list<array<string, mixed>>  $keywords
     * @return array{ok: bool, reason: ?string, quote: ?string, keywords: list<array<string, mixed>>, discarded: array<string, int>}
     */
    private function fail(string $reason, array $discarded, ?string $quote = null, array $keywords = []): array
    {
        return ['ok' => false, 'reason' => $reason, 'quote' => $quote, 'keywords' => $keywords, 'discarded' => $discarded];
    }
}
