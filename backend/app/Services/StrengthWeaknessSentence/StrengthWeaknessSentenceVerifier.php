<?php

namespace App\Services\StrengthWeaknessSentence;

/**
 * 依頼CV-3: AIが書いた強み・弱みの一文を、渡した材料と突き合わせる(ここが本体)。強み・弱みそれぞれについて、
 * 次のすべてを満たすときだけ使う。1つでも満たさなければ、その側だけ依頼CT-1の文に戻す。確認を緩めて、AIの文を通さないこと。
 *
 *  1. quote が、渡した根拠の文にそのまま含まれる(空白・全角半角の違いだけ無視)
 *  2. quote の長さが config の範囲内(既定 10〜35字)
 *  3. quote から、比較に入っている全社の企業名(法人格を除いた言い換えを含む)を除いた残りが config の長さ以上(既定 8字)
 *     ―― 会社名だけの引用を落とす
 *  4. quote が「は」「では」「が」「の」「を」「に」「も」(config)で終わらない ―― 切れ端の引用を落とす
 *  5. (依頼CV追補) 引用の「」はサーバーが入れる: AIは sentence の中に {QUOTE} をちょうど1つ書き、sentence に「」『』を1つも書かない。
 *     サーバーが {QUOTE} を「quote」に置き換えてから、1〜4・6〜9 を確かめる(旧5「」の中が quote と一致する、は、これで置き換わった)
 *  6. sentence に出てくる企業名は、渡した会社だけ(強みは自社だけ、弱みは自社と取り上げた競合だけ)。
 *     記号で見出しを出す比較では、「競合X」のXが取り上げた競合の記号であること
 *  7. sentence の数字が、すべて材料(根拠の文・競合の社数)の中にある
 *  8. 禁止の言葉(依頼CUと同じ config('comparison_commentary.forbidden_words'))を含まない
 *  9. sentence が上限(config、既定100字)以内で、空でない
 *
 * 落ちた理由の符号(reasons)は件数としてログに出す。文の中身は出さない。
 */
class StrengthWeaknessSentenceVerifier
{
    public const REASON_EMPTY = 'empty_sentence';

    public const REASON_QUOTE_NOT_IN_EVIDENCE = 'quote_not_in_evidence';

    public const REASON_QUOTE_LENGTH = 'quote_length';

    public const REASON_QUOTE_COMPANY_ONLY = 'quote_company_name_only';

    public const REASON_QUOTE_ENDING = 'quote_bad_ending';

    /** sentence(置き換える前)に「」『』がある。 */
    public const REASON_BRACKET = 'bracketed_word_not_quote';

    /** sentence の {QUOTE} が、ちょうど1つでない。 */
    public const REASON_PLACEHOLDER = 'placeholder_count';

    public const REASON_COMPANY = 'company_not_allowed';

    public const REASON_NUMBER = 'number_not_in_material';

    public const REASON_FORBIDDEN = 'forbidden_word';

    public const REASON_TOO_LONG = 'too_long';

    /**
     * @param  'strength'|'weakness'  $side
     * @param  array<string, mixed>  $output  AIの出力のその側(sentence/quote)
     * @param  array<string, mixed>  $material  StrengthWeaknessSentenceMaterialBuilder::fromBase()の戻り値
     * @return array{ok: bool, reasons: list<string>, value: ?array{sentence: string, quote: string}}
     */
    public function verify(string $side, array $output, array $material): array
    {
        $target = $material[$side] ?? null;
        $sentence = is_string($output['sentence'] ?? null) ? trim($output['sentence']) : '';
        $quote = is_string($output['quote'] ?? null) ? trim($output['quote']) : '';

        if ($target === null || $sentence === '') {
            return ['ok' => false, 'reasons' => [self::REASON_EMPTY], 'value' => null];
        }

        $reasons = [];

        // 5. 引用の「」はサーバーが入れる: {QUOTE}がちょうど1つ、「」『』は1つも無い(置き換える前の文で確かめる)。
        $placeholder = (string) config('strength_weakness_sentence.quote_placeholder');
        if ($placeholder === '' || substr_count($sentence, $placeholder) !== 1) {
            $reasons[] = self::REASON_PLACEHOLDER;
        }
        foreach ((array) config('strength_weakness_sentence.quote_brackets', []) as [$open, $close]) {
            if (str_contains($sentence, (string) $open) || str_contains($sentence, (string) $close)) {
                $reasons[] = self::REASON_BRACKET;

                break;
            }
        }
        // 置き換えたあとの文で、残りの確認(6〜9)を行う({QUOTE}が1つでないときは、置き換えずにそのまま)。
        if ($placeholder !== '' && substr_count($sentence, $placeholder) === 1) {
            $sentence = str_replace($placeholder, '「'.$quote.'」', $sentence);
        }

        $evidence = (string) $target['evidence'];
        $normalizedQuote = $this->normalize($quote);

        // 1. quoteが根拠の文にそのまま含まれる
        if ($normalizedQuote === '' || ! str_contains($this->normalize($evidence), $normalizedQuote)) {
            $reasons[] = self::REASON_QUOTE_NOT_IN_EVIDENCE;
        }

        // 2. quoteの長さ
        $length = mb_strlen($quote);
        if ($length < (int) config('strength_weakness_sentence.quote_min_chars') || $length > (int) config('strength_weakness_sentence.quote_max_chars')) {
            $reasons[] = self::REASON_QUOTE_LENGTH;
        }

        // 3. 会社名を除いた残り
        if (mb_strlen($this->withoutCompanyNames($quote, $material)) < (int) config('strength_weakness_sentence.quote_rest_min_chars')) {
            $reasons[] = self::REASON_QUOTE_COMPANY_ONLY;
        }

        // 4. 助詞で終わる切れ端
        foreach ((array) config('strength_weakness_sentence.quote_bad_endings', []) as $ending) {
            if ($ending !== '' && str_ends_with($quote, (string) $ending)) {
                $reasons[] = self::REASON_QUOTE_ENDING;

                break;
            }
        }

        // 6. 渡した会社だけ
        if (! $this->companiesOk($side, $sentence, $material)) {
            $reasons[] = self::REASON_COMPANY;
        }

        // 7. 数字(根拠の文・競合の社数・調査の割合。調査の割合は、強みを調査の割合で選んだときの材料)
        $materialNumbers = $this->numbers($evidence.' '.$target['competitor_count'].' '.$target['matched'].' '.($target['survey']['percentage'] ?? ''));
        foreach ($this->numbers($sentence) as $number) {
            if (! in_array($number, $materialNumbers, true)) {
                $reasons[] = self::REASON_NUMBER;

                break;
            }
        }

        // 8. 禁止の言葉
        foreach ((array) config('comparison_commentary.forbidden_words', []) as $word) {
            if ($word !== '' && str_contains($sentence, (string) $word)) {
                $reasons[] = self::REASON_FORBIDDEN;

                break;
            }
        }

        // 9. 長さ
        if (mb_strlen($sentence) > (int) config('strength_weakness_sentence.sentence_limit_chars')) {
            $reasons[] = self::REASON_TOO_LONG;
        }

        if ($reasons !== []) {
            return ['ok' => false, 'reasons' => $reasons, 'value' => null];
        }

        return ['ok' => true, 'reasons' => [], 'value' => ['sentence' => $sentence, 'quote' => $quote]];
    }

    /**
     * quoteから、全社の企業名(法人格を除いた言い換えを含む)と、記号・空白を除いた残り。
     *
     * @param  array<string, mixed>  $material
     */
    private function withoutCompanyNames(string $quote, array $material): string
    {
        $names = (array) ($material['self_names'] ?? []);
        foreach ((array) ($material['competitors'] ?? []) as $competitor) {
            array_push($names, ...(array) ($competitor['variants'] ?? []));
        }
        usort($names, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $rest = $quote;
        foreach ($names as $name) {
            $rest = str_replace($name, '', $rest);
        }

        return (string) preg_replace('/[\s\p{P}\p{S}]+/u', '', $rest);
    }

    /**
     * @param  array<string, mixed>  $material
     */
    private function companiesOk(string $side, string $sentence, array $material): bool
    {
        $target = $material[$side];
        $allowedLabel = $side === 'weakness' ? (string) $target['company_label'] : null;

        foreach ((array) ($material['competitors'] ?? []) as $competitor) {
            // 記号表示のときは、名前ではなく「競合X」の記号で確かめる(下)。名前そのものも、取り上げた会社以外は出さない。
            if ($competitor['label'] === $allowedLabel) {
                continue;
            }
            foreach ((array) $competitor['variants'] as $variant) {
                if (str_contains($sentence, $variant)) {
                    return false;
                }
            }
        }

        if (($material['symbol_mode'] ?? false) === true) {
            $prefix = preg_quote(str_replace('%s', '', (string) config('strength_weakness_sentence.symbol_label_template')), '/');
            if ($prefix !== '' && preg_match_all('/'.$prefix.'([A-Za-z0-9])/u', $sentence, $m)) {
                foreach ($m[1] as $label) {
                    if ($label !== $allowedLabel) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @return list<string> 半角にそろえた数字の並び
     */
    private function numbers(string $text): array
    {
        preg_match_all('/\d+(?:\.\d+)?/', mb_convert_kana($text, 'n'), $m);

        return array_values(array_unique($m[0]));
    }

    /** 空白をすべて除き、全角半角の違いをそろえる(依頼CTのBranchSurveyItemsVerifierと同じ)。 */
    private function normalize(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', mb_convert_kana($text, 'asKV'));
    }
}
