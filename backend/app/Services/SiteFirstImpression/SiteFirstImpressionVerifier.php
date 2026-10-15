<?php

namespace App\Services\SiteFirstImpression;

/**
 * 依頼CX-3: AIが書いた「第一印象」の各点(title・body・evidence)を、構造(材料)と突き合わせる(ここが本体)。
 * 各点について、次のすべてを満たすときだけ使う。満たさない点は捨てる。確認を緩めて点を通さないこと。
 *
 *  1. evidence が1つ以上あり、各 id が構造に実在する
 *  2. 各 evidence.text が、その id の要素の文字にそのまま含まれる(空白・全角半角の違いだけ無視)
 *  3. body の中の「」『』で囲まれた言葉(いちばん外側のもの)が、すべていずれかの evidence.text と一致する
 *  4. body に数字があれば、その数字が evidence.text か、構造の情報(順番・項目数)にある
 *  5. body に企業名が出るなら、自社の名前だけ(同じ比較の他社の名前は出さない)
 *  6. 禁止の言葉(依頼CUと同じ config('comparison_commentary.forbidden_words') と、強すぎる評価の言葉
 *     config('site_first_impression.extra_forbidden_words'))を含まない
 *  7. 長さ(title 25字・body 120字)以内
 *
 * 捨てたあと、good・bad のどちらかが config の最小件数(既定1件)未満なら、ページは作らない(呼び出し側)。
 * 捨てた理由の符号は件数としてログに出す。本文は出さない。
 */
class SiteFirstImpressionVerifier
{
    public const REASON_NO_EVIDENCE = 'evidence_missing';

    public const REASON_UNKNOWN_ID = 'evidence_id_unknown';

    public const REASON_TEXT_NOT_IN_ELEMENT = 'evidence_text_not_in_element';

    public const REASON_BRACKET = 'bracketed_word_not_evidence';

    public const REASON_NUMBER = 'number_not_in_structure';

    public const REASON_COMPANY = 'company_not_allowed';

    public const REASON_FORBIDDEN = 'forbidden_word';

    public const REASON_TOO_LONG = 'too_long';

    public const REASON_EMPTY = 'empty_title_or_body';

    /**
     * @param  array<string, mixed>  $point  AIの出力の1点(title / body / evidence)
     * @param  array<string, mixed>  $material  SiteFirstImpressionMaterialBuilder::build()の戻り値
     * @return array{ok: bool, reasons: list<string>, value: ?array{title: string, body: string, evidence: list<array{id: string, text: string}>}}
     */
    public function verify(array $point, array $material): array
    {
        $title = is_string($point['title'] ?? null) ? trim($point['title']) : '';
        $body = is_string($point['body'] ?? null) ? trim($point['body']) : '';
        if ($title === '' || $body === '') {
            return ['ok' => false, 'reasons' => [self::REASON_EMPTY], 'value' => null];
        }

        $reasons = [];
        $elements = (array) $material['elements'];

        // 1・2. evidence
        $evidence = [];
        foreach ((array) ($point['evidence'] ?? []) as $row) {
            if (is_array($row) && is_string($row['id'] ?? null) && is_string($row['text'] ?? null)) {
                $evidence[] = ['id' => trim($row['id']), 'text' => trim($row['text'])];
            }
        }
        if ($evidence === []) {
            $reasons[] = self::REASON_NO_EVIDENCE;
        }
        $unknown = false;
        $notIn = false;
        foreach ($evidence as $row) {
            if (! array_key_exists($row['id'], $elements)) {
                $unknown = true;
            } elseif ($this->normalize($row['text']) === '' || ! str_contains($this->normalize((string) $elements[$row['id']]), $this->normalize($row['text']))) {
                $notIn = true;
            }
        }
        if ($unknown) {
            $reasons[] = self::REASON_UNKNOWN_ID;
        }
        if ($notIn) {
            $reasons[] = self::REASON_TEXT_NOT_IN_ELEMENT;
        }

        // 3. 「」の中の言葉
        $evidenceTexts = array_map(fn (array $row) => $this->normalize($row['text']), $evidence);
        foreach ($this->bracketed($body) as $word) {
            if (! in_array($this->normalize($word), $evidenceTexts, true)) {
                $reasons[] = self::REASON_BRACKET;

                break;
            }
        }

        // 4. 数字(evidence.text・構造の情報)
        $allowed = $this->numbers(implode(' ', array_column($evidence, 'text')));
        foreach ((array) $material['numbers'] as $number) {
            $allowed[] = (string) $number;
        }
        foreach ($this->numbers($body) as $number) {
            if (! in_array($number, $allowed, true)) {
                $reasons[] = self::REASON_NUMBER;

                break;
            }
        }

        // 5. 企業名(自社の名前だけ)
        foreach ((array) $material['other_names'] as $name) {
            if (str_contains($body, (string) $name) || str_contains($title, (string) $name)) {
                $reasons[] = self::REASON_COMPANY;

                break;
            }
        }

        // 6. 禁止の言葉
        foreach (array_merge((array) config('comparison_commentary.forbidden_words', []), (array) config('site_first_impression.extra_forbidden_words', [])) as $word) {
            if ($word !== '' && (str_contains($body, (string) $word) || str_contains($title, (string) $word))) {
                $reasons[] = self::REASON_FORBIDDEN;

                break;
            }
        }

        // 7. 長さ
        if (mb_strlen($title) > (int) config('site_first_impression.title_max_chars', 25) || mb_strlen($body) > (int) config('site_first_impression.body_max_chars', 120)) {
            $reasons[] = self::REASON_TOO_LONG;
        }

        if ($reasons !== []) {
            return ['ok' => false, 'reasons' => $reasons, 'value' => null];
        }

        return ['ok' => true, 'reasons' => [], 'value' => ['title' => $title, 'body' => $body, 'evidence' => $evidence]];
    }

    /**
     * 良い・悪いの各点をまとめて確認し、通ったものだけを上限(config)まで残す。
     *
     * @param  array<string, mixed>  $output  AIの出力(good / bad)
     * @param  array<string, mixed>  $material
     * @return array{good: list<array<string, mixed>>, bad: list<array<string, mixed>>, discarded: array<string, int>}
     */
    public function verifyAll(array $output, array $material): array
    {
        $max = (int) config('site_first_impression.points_max', 4);
        $discarded = [];
        $kept = ['good' => [], 'bad' => []];

        foreach (['good', 'bad'] as $side) {
            foreach (array_slice((array) ($output[$side] ?? []), 0, $max) as $point) {
                $checked = $this->verify(is_array($point) ? $point : [], $material);
                if ($checked['ok']) {
                    $kept[$side][] = $checked['value'];
                } else {
                    foreach ($checked['reasons'] as $reason) {
                        $discarded[$reason] = ($discarded[$reason] ?? 0) + 1;
                    }
                }
            }
        }

        return ['good' => $kept['good'], 'bad' => $kept['bad'], 'discarded' => $discarded];
    }

    /**
     * 文の中の、「」『』で囲まれた言葉(いちばん外側のものだけ)。閉じていない括弧は、空の言葉として数える(一致しない)。
     *
     * @return list<string>
     */
    private function bracketed(string $text): array
    {
        $pairs = [];
        foreach ((array) config('site_first_impression.quote_brackets', []) as [$open, $close]) {
            $pairs[$open] = $close;
        }
        $opens = array_keys($pairs);
        $closes = array_values($pairs);

        $words = [];
        $stack = [];
        $buffer = '';
        foreach (mb_str_split($text) as $char) {
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
                $words[] = '';
            }
        }
        if ($stack !== []) {
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

    /** 空白をすべて除き、全角半角の違いをそろえる(依頼CTのBranchSurveyItemsVerifierと同じ)。 */
    private function normalize(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', mb_convert_kana($text, 'asKV'));
    }
}
