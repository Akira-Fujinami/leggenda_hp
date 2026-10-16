<?php

namespace App\Services\StrengthWeaknessInsight;

/**
 * 依頼DB-4: Claudeが書いた強み・弱みを、渡した材料と突き合わせる。確認するのは「事実の部分」だけ
 * (解釈・言い回しは確かめない。禁止の言葉の一覧(依頼CU)は使わない)。強み・弱みそれぞれ、次のすべてを満たすときだけ使う。
 * 1つでも満たさなければ、その側は依頼CT-1の文に戻す。確認を緩めて、AIの文を通さないこと。
 *
 *  1. text の中の「」の言葉が、すべて quotes のどれかと一致する(空白・全角半角の違いだけ無視)。
 *     「」が1つも無い(根拠の引用が無い)側も落とす(config('strength_weakness_insight.require_quote'))
 *  2. 各 quotes.text が、その会社の材料(根拠の文。自社なら採用TOPの構造の文字も)に、そのまま含まれる(空白・全角半角の違いだけ無視)。
 *     company が比較に入っている会社(名前・法人格を除いた言い換え・記号・自社の呼び方)でないものも落とす
 *  3. text に出る会社名(「株式会社〇〇」「〇〇株式会社」の形。「」の中は材料の言葉なので見ない)は、比較に入っている会社だけ
 *  4. text に数字があれば、材料(点数・分母・合計・社数・根拠の文・構造の順番や項目数)にある
 *  5. text の長さが上限以内(config('strength_weakness_insight.text_max_chars'))
 *  6. text が、強すぎる評価の言葉(config('strength_weakness_insight.strong_evaluation_words'))を含まない
 *
 * 限界: 3. は、法人格つきで書かれた会社名しか見つけられない(法人格の無い他社名は、見つけられない)。
 * 落ちた理由の符号(reasons)は件数としてログに出す。文・引用の中身は出さない。
 */
class StrengthWeaknessInsightVerifier
{
    public const REASON_MALFORMED = 'malformed';

    public const REASON_EMPTY = 'empty_text';

    public const REASON_TOO_LONG = 'text_too_long';

    public const REASON_NO_QUOTE = 'no_quote';

    public const REASON_QUOTE_MISMATCH = 'bracket_not_in_quotes';

    public const REASON_QUOTE_TOO_SHORT = 'quote_too_short';

    public const REASON_QUOTE_COMPANY = 'quote_company_unknown';

    public const REASON_QUOTE_NOT_IN_MATERIAL = 'quote_not_in_material';

    public const REASON_COMPANY_NAME = 'unknown_company_name';

    public const REASON_NUMBER = 'number_not_in_material';

    public const REASON_STRONG_WORD = 'strong_evaluation_word';

    /**
     * @param  'strength'|'weakness'  $side
     * @param  mixed  $candidate  AIの出力の strength / weakness(['text' => string, 'quotes' => list<['company' => string, 'text' => string]>])
     * @param  array<string, mixed>  $material  StrengthWeaknessInsightMaterialBuilder::fromBase()の戻り値
     * @return array{ok: bool, reasons: list<string>, value: ?array{sentence: string, quotes: list<array{company: string, text: string}>}}
     */
    public function verify(string $side, mixed $candidate, array $material): array
    {
        if (! is_array($candidate) || ! is_string($candidate['text'] ?? null) || ! is_array($candidate['quotes'] ?? null)) {
            return $this->fail([self::REASON_MALFORMED]);
        }

        $text = trim($candidate['text']);
        if ($text === '') {
            return $this->fail([self::REASON_EMPTY]);
        }

        $reasons = [];

        // 5. 長さ
        if (mb_strlen($text) > (int) config('strength_weakness_insight.text_max_chars')) {
            $reasons[] = self::REASON_TOO_LONG;
        }

        // 1. 「」の言葉は、すべて quotes のどれかと一致する
        $quotes = [];
        foreach ($candidate['quotes'] as $quote) {
            if (is_array($quote) && is_string($quote['company'] ?? null) && is_string($quote['text'] ?? null)) {
                $quotes[] = ['company' => trim($quote['company']), 'text' => trim($quote['text'])];
            }
        }
        [$segments, $balanced] = $this->bracketSegments($text);
        if ((bool) config('strength_weakness_insight.require_quote', true) && $segments === []) {
            $reasons[] = self::REASON_NO_QUOTE;
        }
        $quoteKeys = array_map(fn (array $q) => $this->loose($q['text']), $quotes);
        if (! $balanced) {
            $reasons[] = self::REASON_QUOTE_MISMATCH;
        }
        foreach ($segments as $segment) {
            if (! in_array($this->loose($segment), $quoteKeys, true)) {
                $reasons[] = self::REASON_QUOTE_MISMATCH;

                break;
            }
        }

        // 2. quotes は、その会社の材料にそのまま含まれる
        $minChars = (int) config('strength_weakness_insight.quote_min_chars', 4);
        foreach ($quotes as $quote) {
            if (mb_strlen($quote['text']) < $minChars) {
                $reasons[] = self::REASON_QUOTE_TOO_SHORT;

                break;
            }
        }
        foreach ($quotes as $quote) {
            $companyKey = $this->resolveCompany($quote['company'], $material);
            if ($companyKey === null) {
                $reasons[] = self::REASON_QUOTE_COMPANY;

                break;
            }
            $corpus = $companyKey === 'self' ? (array) $material['corpus']['self'] : (array) ($material['corpus']['competitors'][$companyKey] ?? []);
            $found = false;
            foreach ($corpus as $source) {
                if ($quote['text'] !== '' && str_contains($this->loose((string) $source), $this->loose($quote['text']))) {
                    $found = true;

                    break;
                }
            }
            if (! $found) {
                $reasons[] = self::REASON_QUOTE_NOT_IN_MATERIAL;

                break;
            }
        }

        // 3. 会社名(「」の外)は、比較に入っている会社だけ
        $outside = (string) preg_replace('/「[^「」]*」/u', '', $text);
        if ($this->hasUnknownCompanyName($outside, $material)) {
            $reasons[] = self::REASON_COMPANY_NAME;
        }

        // 4. 数字は、材料にあるものだけ
        $allowed = array_flip(array_map('strval', (array) ($material['numbers'] ?? [])));
        foreach (StrengthWeaknessInsightMaterialBuilder::extractNumbers($text) as $number) {
            if (! isset($allowed[$number])) {
                $reasons[] = self::REASON_NUMBER;

                break;
            }
        }

        // 6. 強すぎる評価の言葉
        foreach ((array) config('strength_weakness_insight.strong_evaluation_words', []) as $word) {
            if ((string) $word !== '' && str_contains($text, (string) $word)) {
                $reasons[] = self::REASON_STRONG_WORD;

                break;
            }
        }

        if ($reasons !== []) {
            return $this->fail(array_values(array_unique($reasons)));
        }

        return ['ok' => true, 'reasons' => [], 'value' => ['sentence' => $text, 'quotes' => $quotes]];
    }

    /**
     * @param  list<string>  $reasons
     * @return array{ok: bool, reasons: list<string>, value: null}
     */
    private function fail(array $reasons): array
    {
        return ['ok' => false, 'reasons' => $reasons, 'value' => null];
    }

    /**
     * 「」の中の言葉(入れ子は無い前提)。開きと閉じの数が合わないときは、第2の戻り値がfalse。
     *
     * @return array{0: list<string>, 1: bool}
     */
    private function bracketSegments(string $text): array
    {
        preg_match_all('/「([^「」]*)」/u', $text, $m);
        $segments = array_values(array_filter(array_map('trim', $m[1]), fn (string $s) => $s !== ''));
        $balanced = mb_substr_count($text, '「') === mb_substr_count($text, '」') && mb_substr_count($text, '「') === count($m[1]);

        return [$segments, $balanced];
    }

    /**
     * quotes.company を、'self' か競合の添字(0始まり)にする。名前・法人格を除いた言い換え・記号・自社の呼び方。比較に入っていなければnull。
     *
     * @param  array<string, mixed>  $material
     */
    private function resolveCompany(string $company, array $material): string|int|null
    {
        $key = $this->loose($company);
        if ($key === '') {
            return null;
        }
        $selfWords = array_merge((array) ($material['self_names'] ?? []), array_map('strval', (array) config('strength_weakness_insight.self_aliases', [])));
        foreach ($selfWords as $word) {
            if ($this->loose((string) $word) === $key) {
                return 'self';
            }
        }
        foreach ((array) ($material['competitors'] ?? []) as $i => $competitor) {
            foreach (array_merge((array) $competitor['variants'], [(string) $competitor['label']]) as $word) {
                if ($this->loose((string) $word) === $key) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * 「株式会社〇〇」「〇〇株式会社」の形で出る名前が、比較に入っている会社でないものを含むか。
     *
     * @param  array<string, mixed>  $material
     */
    private function hasUnknownCompanyName(string $text, array $material): bool
    {
        $known = (array) ($material['self_names'] ?? []);
        foreach ((array) ($material['competitors'] ?? []) as $competitor) {
            array_push($known, ...(array) $competitor['variants']);
        }
        $known = array_values(array_filter(array_map(fn ($n) => $this->loose((string) $n), $known), fn (string $n) => mb_strlen($n) >= 2));

        $name = '[^\s、。「」『』（）()・，,：:；;!！?？]{2,20}';
        $particles = ['の', 'は', 'が', 'を', 'に', 'や', 'と', 'で', 'も', 'へ'];
        foreach ((array) config('strength_weakness_insight.company_legal_forms', []) as $form) {
            $form = preg_quote((string) $form, '/');
            $tokens = [];
            if (preg_match_all('/'.$form.'\s*('.$name.')/u', $text, $m)) {
                array_push($tokens, ...$m[1]);
            }
            if (preg_match_all('/('.$name.')\s*'.$form.'/u', $text, $m)) {
                array_push($tokens, ...$m[1]);
            }
            foreach ($tokens as $token) {
                $key = $this->loose((string) $token);
                if (in_array(mb_substr($key, 0, 1), $particles, true)) {
                    continue;
                }
                $isKnown = false;
                foreach ($known as $name2) {
                    if (str_contains($key, $name2) || str_contains($name2, $key)) {
                        $isKnown = true;

                        break;
                    }
                }
                if (! $isKnown) {
                    return true;
                }
            }
        }

        return false;
    }

    /** 空白をすべて除き、全角半角の違い・大文字小文字をそろえる。 */
    private function loose(string $text): string
    {
        return mb_strtolower((string) preg_replace('/[\s\x{3000}\x{00A0}]+/u', '', mb_convert_kana($text, 'asKV')));
    }
}
