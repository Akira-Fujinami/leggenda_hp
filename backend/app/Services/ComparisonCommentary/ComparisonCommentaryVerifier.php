<?php

namespace App\Services\ComparisonCommentary;

/**
 * 依頼CU-3: AIが書いた強み・弱みの一文を、材料(ComparisonCommentaryMaterialBuilder)と突き合わせる(ここが本体)。
 * 強み・弱みそれぞれについて、次のすべてを満たすときだけ使う。1つでも満たさなければ、その側だけ依頼CT-1の一文に戻す。
 * 確認を緩めて、AIの文を通さないこと。
 *
 *  1. items がすべて24項目のキーで、強みの項目は自社が○、弱みの項目は自社が×(弱みは items が空でないこと)
 *  2. quotes がすべて、材料に入れた根拠の文の中に、そのまま含まれる(空白・全角半角の違いだけ無視)。
 *     さらに、強みの quotes は自社の根拠の文(items の項目)から、弱みの quotes は文に出した競合の根拠の文(items の項目)から
 *  3. sentence の中の「」『』で囲まれた言葉が、すべて quotes のどれかと一致する
 *  4. companies がすべて比較に入っている競合の呼び名で、弱みの項目のすべてでその会社が○。
 *     (企業名で見出しを出すときは)companies に無い競合の名前を文に出していない
 *  5. sentence の数字が、すべて材料の中にある
 *  6. 禁止の言葉(config)を含まない
 *  7. 長さが上限(config)以内
 *
 * 落ちた理由の符号(reasons)は件数としてログに出す。文の中身は出さない。
 */
class ComparisonCommentaryVerifier
{
    public const REASON_EMPTY = 'empty_sentence';

    public const REASON_ITEMS = 'items_invalid';

    public const REASON_QUOTE_NOT_IN_MATERIAL = 'quote_not_in_material';

    public const REASON_QUOTE_NOT_IN_ITEMS = 'quote_not_in_items';

    public const REASON_BRACKET = 'bracketed_word_not_in_quotes';

    public const REASON_COMPANY = 'company_invalid';

    public const REASON_NUMBER = 'number_not_in_material';

    public const REASON_FORBIDDEN = 'forbidden_word';

    public const REASON_TOO_LONG = 'too_long';

    /**
     * @param  'strength'|'weakness'  $side
     * @param  array<string, mixed>  $output  AIの出力のその側(sentence/items/quotes/companies)
     * @param  array<string, mixed>  $material  ComparisonCommentaryMaterialBuilder::build()の戻り値
     * @return array{ok: bool, reasons: list<string>, value: ?array{sentence: string, items: list<string>, quotes: list<string>, companies: list<string>}}
     */
    public function verify(string $side, array $output, array $material): array
    {
        $sentence = is_string($output['sentence'] ?? null) ? trim($output['sentence']) : '';
        $items = $this->strings($output['items'] ?? []);
        $quotes = $this->strings($output['quotes'] ?? []);
        $companies = $side === 'weakness' ? $this->strings($output['companies'] ?? []) : [];

        if ($sentence === '') {
            return ['ok' => false, 'reasons' => [self::REASON_EMPTY], 'value' => null];
        }

        $reasons = [];

        // 1. items
        $selfMatched = (array) ($material['self_matched_by_key'] ?? []);
        $itemsOk = ($side === 'weakness' ? $items !== [] : true) && count($items) === count(array_unique($items));
        foreach ($items as $key) {
            if (! array_key_exists($key, $selfMatched) || ($side === 'strength' ? $selfMatched[$key] !== true : $selfMatched[$key] !== false)) {
                $itemsOk = false;
            }
        }
        if (! $itemsOk) {
            $reasons[] = self::REASON_ITEMS;
        }

        // 2. quotes
        $evidences = $this->evidenceSources($side, $material, $items, $companies);
        $allEvidences = $this->evidenceSources($side, $material, null, null);
        $quoteMaterialBad = false;
        $quoteItemsBad = false;
        foreach ($quotes as $quote) {
            $needle = $this->normalize($quote);
            if ($needle === '' || ! $this->containedIn($needle, $allEvidences)) {
                $quoteMaterialBad = true;
            } elseif (! $this->containedIn($needle, $evidences)) {
                $quoteItemsBad = true;
            }
        }
        if ($quoteMaterialBad) {
            $reasons[] = self::REASON_QUOTE_NOT_IN_MATERIAL;
        }
        if ($quoteItemsBad) {
            $reasons[] = self::REASON_QUOTE_NOT_IN_ITEMS;
        }

        // 3. 「」の中の言葉
        $normalizedQuotes = array_map(fn (string $q) => $this->normalize($q), $quotes);
        foreach ($this->bracketed($sentence) as $word) {
            if (! in_array($this->normalize($word), $normalizedQuotes, true)) {
                $reasons[] = self::REASON_BRACKET;
                break;
            }
        }

        // 4. companies
        if ($side === 'weakness' && ! $this->companiesOk($companies, $items, $sentence, $material)) {
            $reasons[] = self::REASON_COMPANY;
        }

        // 5. 数字
        $materialNumbers = $this->numbers((string) json_encode($this->visibleOf($material), JSON_UNESCAPED_UNICODE));
        foreach ($this->numbers($sentence) as $number) {
            if (! in_array($number, $materialNumbers, true)) {
                $reasons[] = self::REASON_NUMBER;
                break;
            }
        }

        // 6. 禁止の言葉
        foreach ((array) config('comparison_commentary.forbidden_words', []) as $word) {
            if ($word !== '' && str_contains($sentence, (string) $word)) {
                $reasons[] = self::REASON_FORBIDDEN;
                break;
            }
        }

        // 7. 長さ
        if (mb_strlen($sentence) > (int) config('comparison_commentary.sentence_limit_chars', 100)) {
            $reasons[] = self::REASON_TOO_LONG;
        }

        if ($reasons !== []) {
            return ['ok' => false, 'reasons' => $reasons, 'value' => null];
        }

        return ['ok' => true, 'reasons' => [], 'value' => ['sentence' => $sentence, 'items' => $items, 'quotes' => $quotes, 'companies' => $companies]];
    }

    /**
     * その側の quotes の取り出し元になる根拠の文(正規化済み)。items/companies が null のときは、材料の中のすべて。
     *
     * @param  ?list<string>  $items
     * @param  ?list<string>  $companies
     * @return list<string>
     */
    private function evidenceSources(string $side, array $material, ?array $items, ?array $companies): array
    {
        $sources = [];
        if ($side === 'strength') {
            foreach ((array) ($material['only_strengths'] ?? []) as $row) {
                if ($items === null || in_array($row['key'], $items, true)) {
                    $sources[] = $this->normalize((string) $row['self_evidence']);
                }
            }
        } else {
            foreach ((array) ($material['holes'] ?? []) as $row) {
                if ($items !== null && ! in_array($row['key'], $items, true)) {
                    continue;
                }
                foreach ((array) $row['competitor_evidence'] as $evidence) {
                    if ($companies === null || in_array($evidence['company'], $companies, true)) {
                        $sources[] = $this->normalize((string) $evidence['evidence']);
                    }
                }
            }
        }

        return array_values(array_filter($sources, fn (string $s) => $s !== ''));
    }

    /**
     * @param  list<string>  $sources
     */
    private function containedIn(string $needle, array $sources): bool
    {
        foreach ($sources as $source) {
            if (str_contains($source, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $companies
     * @param  list<string>  $items
     */
    private function companiesOk(array $companies, array $items, string $sentence, array $material): bool
    {
        $labels = array_column((array) ($material['competitors'] ?? []), 'label');
        $matched = (array) ($material['matched_labels_by_key'] ?? []);

        foreach ($companies as $company) {
            if (! in_array($company, $labels, true)) {
                return false;
            }
            foreach ($items as $key) {
                if (! in_array($company, (array) ($matched[$key] ?? []), true)) {
                    return false;
                }
            }
        }

        // 企業名で見出しを出すとき(label = 企業名)、companies に無い競合の名前を文に出していない。
        foreach ((array) ($material['competitors'] ?? []) as $competitor) {
            if ($competitor['label'] === $competitor['name'] && $competitor['name'] !== '' && str_contains($sentence, $competitor['name']) && ! in_array($competitor['label'], $companies, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 文の中の、「」『』で囲まれた言葉(いちばん外側のものだけ)。外側の括弧の中にある括弧は、引用した言葉の一部なので
     * 別には数えない(外側の言葉が quotes と一致し、quotes が根拠の文にそのまま含まれることは、別に確認する)。
     *
     * @return list<string>
     */
    private function bracketed(string $sentence): array
    {
        $pairs = [];
        foreach ((array) config('comparison_commentary.quote_brackets', []) as [$open, $close]) {
            $pairs[$open] = $close;
        }
        $opens = array_keys($pairs);
        $closes = array_values($pairs);

        $words = [];
        $stack = [];
        $buffer = '';
        foreach (mb_str_split($sentence) as $char) {
            if (in_array($char, $opens, true)) {
                $stack[] = $pairs[$char];
                if (count($stack) > 1) {
                    $buffer .= $char;
                }

                continue;
            }
            if ($stack !== [] && $char === end($stack)) {
                array_pop($stack);
                if ($stack === []) {
                    $words[] = $buffer;
                    $buffer = '';
                } else {
                    $buffer .= $char;
                }

                continue;
            }
            if ($stack !== []) {
                $buffer .= $char;
            } elseif (in_array($char, $closes, true)) {
                // 閉じるだけの括弧(開いていない)は、引用として扱えない。
                $words[] = '';
            }
        }
        if ($stack !== []) {
            // 閉じていない括弧。引用として扱えない。
            $words[] = $buffer;
        }

        return $words;
    }

    /**
     * @return list<string> 半角にそろえた数字の並び
     */
    private function numbers(string $text): array
    {
        preg_match_all('/\d+(?:\.\d+)?/', mb_convert_kana($text, 'n'), $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * @param  array<string, mixed>  $material
     * @return array<string, mixed>
     */
    private function visibleOf(array $material): array
    {
        return array_intersect_key($material, array_flip(['self_name', 'competitors', 'competitor_count', 'only_strengths', 'holes', 'tie_axes']));
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : '', $value), fn (string $v) => $v !== ''));
    }

    /** 空白をすべて除き、全角半角の違いをそろえる(BranchSurveyItemsVerifierと同じ)。 */
    private function normalize(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', mb_convert_kana($text, 'asKV'));
    }
}
