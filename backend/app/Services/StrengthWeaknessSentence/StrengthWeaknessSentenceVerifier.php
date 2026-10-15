<?php

namespace App\Services\StrengthWeaknessSentence;

/**
 * 依頼CW-3: AIが選んだ引用(quote)を、渡した材料と突き合わせる(ここが本体)。強み・弱みそれぞれについて、次のすべてを
 * 満たすときだけ使う。1つでも満たさなければ、その側は依頼CT-1の文に戻す。確認を緩めて、AIの引用を通さないこと。
 * 文はサーバーが組み立てる(StrengthWeaknessSentenceAssembler)ので、文そのものの確認は、長さだけ。
 *
 *  1. quote が、渡した根拠の文にそのまま含まれる(空白・全角半角の違いだけ無視)
 *  2. quote の長さが config の範囲内(既定 10〜35字)
 *  3. quote から、比較に入っている全社の企業名(法人格を除いた言い換えを含む)を除いた残りが config の長さ以上(既定 8字)
 *     ―― 会社名だけの引用を落とす
 *  4. quote の末尾が config の一覧(「は」「では」「が」「の」「を」「に」「も」「て」「よう」「ため」「など」)で終わらない
 *     ―― 文の途中で切れた引用・切れ端の引用を落とす
 *  5. quote が config の「感想・心情の言葉」(「感じ」「痛感」「思う」「思った」「気づい」「実感」「印象」)を含まない
 *  6. quote が「」『』を含まない(文の「」はサーバーが付ける)
 *  7. quote が禁止の言葉(依頼CUと同じ config('comparison_commentary.forbidden_words'))を含まない
 *  +  組み立てた文が上限(config、既定100字)以内
 *
 * 落ちた理由の符号(reasons)は件数としてログに出す。引用の中身は出さない。
 */
class StrengthWeaknessSentenceVerifier
{
    public const REASON_EMPTY = 'empty_quote';

    public const REASON_QUOTE_NOT_IN_EVIDENCE = 'quote_not_in_evidence';

    public const REASON_QUOTE_LENGTH = 'quote_length';

    public const REASON_QUOTE_COMPANY_ONLY = 'quote_company_name_only';

    public const REASON_QUOTE_ENDING = 'quote_bad_ending';

    public const REASON_FEELING = 'quote_feeling_word';

    public const REASON_BRACKET = 'quote_has_bracket';

    public const REASON_FORBIDDEN = 'forbidden_word';

    public const REASON_TOO_LONG = 'sentence_too_long';

    public function __construct(private readonly StrengthWeaknessSentenceAssembler $assembler) {}

    /**
     * @param  'strength'|'weakness'  $side
     * @param  ?string  $quote  AIが選んだ引用(対象が無い側はnull)
     * @param  array<string, mixed>  $material  StrengthWeaknessSentenceMaterialBuilder::fromBase()の戻り値
     * @return array{ok: bool, reasons: list<string>, value: ?array{sentence: string, quote: string}}
     */
    public function verify(string $side, ?string $quote, array $material): array
    {
        $target = $material[$side] ?? null;
        $quote = trim((string) $quote);

        if ($target === null || $quote === '') {
            return ['ok' => false, 'reasons' => [self::REASON_EMPTY], 'value' => null];
        }

        $reasons = [];

        // 1. 根拠の文にそのまま含まれる
        if (! str_contains($this->normalize((string) $target['evidence']), $this->normalize($quote))) {
            $reasons[] = self::REASON_QUOTE_NOT_IN_EVIDENCE;
        }

        // 2. 長さ
        $length = mb_strlen($quote);
        if ($length < (int) config('strength_weakness_sentence.quote_min_chars') || $length > (int) config('strength_weakness_sentence.quote_max_chars')) {
            $reasons[] = self::REASON_QUOTE_LENGTH;
        }

        // 3. 会社名を除いた残り
        if (mb_strlen($this->withoutCompanyNames($quote, $material)) < (int) config('strength_weakness_sentence.quote_rest_min_chars')) {
            $reasons[] = self::REASON_QUOTE_COMPANY_ONLY;
        }

        // 4. 途中で切れた引用・切れ端の引用
        foreach ((array) config('strength_weakness_sentence.quote_bad_endings', []) as $ending) {
            if ($ending !== '' && str_ends_with($quote, (string) $ending)) {
                $reasons[] = self::REASON_QUOTE_ENDING;

                break;
            }
        }

        // 5. 個人の感想・心情の言葉
        foreach ((array) config('strength_weakness_sentence.quote_feeling_words', []) as $word) {
            if ($word !== '' && str_contains($quote, (string) $word)) {
                $reasons[] = self::REASON_FEELING;

                break;
            }
        }

        // 6. 「」『』
        foreach ((array) config('strength_weakness_sentence.quote_brackets', []) as [$open, $close]) {
            if (str_contains($quote, (string) $open) || str_contains($quote, (string) $close)) {
                $reasons[] = self::REASON_BRACKET;

                break;
            }
        }

        // 7. 禁止の言葉
        foreach ((array) config('comparison_commentary.forbidden_words', []) as $word) {
            if ($word !== '' && str_contains($quote, (string) $word)) {
                $reasons[] = self::REASON_FORBIDDEN;

                break;
            }
        }

        // 組み立てた文の長さ
        $sentence = $this->assembler->assemble($side, $target, $quote);
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

    /** 空白をすべて除き、全角半角の違いをそろえる(依頼CTのBranchSurveyItemsVerifierと同じ)。 */
    private function normalize(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', mb_convert_kana($text, 'asKV'));
    }
}
