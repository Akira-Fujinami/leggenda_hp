<?php

namespace App\Services\TopMessagePanel;

/**
 * 依頼CZ-3・CZ追補: AIが選んだものを、サーバー側で材料と突き合わせる。通ったものだけをパネルに使う
 * (プロンプトは確認の代わりではない。確認を緩めて通さないこと)。長さ・個数・語の一覧はすべて config。
 *
 *  - 核の一文: IDが実在し、文の長さが core_min_chars〜core_max_chars。通らなければパネルは使わない
 *  - 本文: IDが実在し、核と重複しない(同じIDの重複も落とす)。body_maxを超える分は落とす。足りなければ本文なしでよい
 *  - 制度(programs。AIが先に選ぶ): IDが実在し、重複しない。programs_maxまで。説明(summary)がその制度の抜粋にそのまま含まれる・
 *    summary_min_chars〜summary_max_chars・末尾がconfig('strength_weakness_sentence.quote_bad_endings')(依頼CWと同じ)で終わらない。
 *    説明だけが落ちた制度は、制度名だけのカード(summary=null)にする(落とさない)
 *  - キーワード(考えを表す句): 長さ keyword_min_chars〜keyword_max_chars、keyword_required_char_pattern(ひらがな)を含む、
 *    source_sentence_idの文にそのまま含まれる(空白・全角半角の違いと、「」『』の有無だけ無視)、末尾が上と同じ一覧で終わらない、
 *    禁止の言葉(依頼CUと同じ一覧)を含まない、同じ語句の重複なし
 *  - キーワードに付ける制度(program_ids): 確認を通った制度の中のもので、他のキーワードと重複しない。1キーワードにprograms_per_keyword_maxまで。
 *    keyword_programs_min件に満たないキーワードは捨てる
 *
 * 結果の mode:
 *  - 'keywords': キーワードがkeywords_min個以上(右側はキーワードの帯)
 *  - 'sources' : キーワードがkeywords_min個に満たないが、制度が1件以上ある(右側は制度の出典ページごとの帯。左のパネルはそのまま)
 *  - 核の一文が落ちたときと、制度が1件も無く、キーワードも足りないときは、パネルは使わない(panel=null。依頼CSの素材ページ)
 *
 * 落とした理由は符号ごとの件数で返す(文の中身は返さない・ログにも出さない)。
 */
class TopMessagePanelVerifier
{
    /**
     * @param  array<string, mixed>  $decoded  AIの出力
     * @param  array{sentences: list<array{id: string, text: string}>, programs: list<array{id: string, name: string, excerpt: string, source: string}>}  $material
     * @return array{panel: ?array{mode: string, core: array{id: string, text: string}, body: list<array{id: string, text: string}>, programs: list<array{id: string, name: string, summary: ?string, source: string}>, keywords: list<array{phrase: string, source_sentence_id: string, programs: list<array{id: string, name: string, summary: ?string}>}>}, rejected: array<string, int>}
     */
    public function verify(array $decoded, array $material): array
    {
        $rejected = [];
        $reject = function (string $code) use (&$rejected): void {
            $rejected[$code] = ($rejected[$code] ?? 0) + 1;
        };

        $sentences = [];
        foreach ($material['sentences'] as $sentence) {
            $sentences[$sentence['id']] = $sentence['text'];
        }
        $materialPrograms = [];
        foreach ($material['programs'] as $program) {
            $materialPrograms[$program['id']] = $program;
        }

        // 核の一文
        $coreId = $decoded['core_sentence_id'] ?? null;
        if (! is_string($coreId) || $coreId === '') {
            $reject('core_missing');

            return ['panel' => null, 'rejected' => $rejected];
        }
        if (! isset($sentences[$coreId])) {
            $reject('core_unknown_id');

            return ['panel' => null, 'rejected' => $rejected];
        }
        $coreLength = mb_strlen($sentences[$coreId]);
        if ($coreLength < (int) config('top_message_panel.core_min_chars') || $coreLength > (int) config('top_message_panel.core_max_chars')) {
            $reject('core_length');

            return ['panel' => null, 'rejected' => $rejected];
        }

        // 本文
        $body = [];
        $bodyMax = (int) config('top_message_panel.body_max');
        foreach ((array) ($decoded['body_sentence_ids'] ?? []) as $id) {
            if (! is_string($id) || ! isset($sentences[$id])) {
                $reject('body_unknown_id');

                continue;
            }
            if ($id === $coreId || isset($body[$id])) {
                $reject('body_duplicate');

                continue;
            }
            if (count($body) >= $bodyMax) {
                $reject('body_over_limit');

                continue;
            }
            $body[$id] = ['id' => $id, 'text' => $sentences[$id]];
        }

        // 制度(AIが先に選ぶ)
        $programs = [];
        $programsMax = (int) config('top_message_panel.programs_max');
        foreach ((array) ($decoded['programs'] ?? []) as $program) {
            $programId = is_array($program) ? (string) ($program['program_id'] ?? '') : '';
            if (! isset($materialPrograms[$programId])) {
                $reject('program_unknown_id');

                continue;
            }
            if (isset($programs[$programId])) {
                $reject('program_duplicate');

                continue;
            }
            if (count($programs) >= $programsMax) {
                $reject('program_over_limit');

                continue;
            }
            $programs[$programId] = [
                'id' => $programId,
                'name' => $materialPrograms[$programId]['name'],
                'summary' => $this->checkedSummary((string) ($program['summary'] ?? ''), $materialPrograms[$programId]['excerpt'], $reject),
                'source' => $materialPrograms[$programId]['source'],
            ];
        }

        // キーワード
        $keywords = $this->keywords((array) ($decoded['keywords'] ?? []), $sentences, $programs, $reject);

        $mode = count($keywords) >= (int) config('top_message_panel.keywords_min') ? 'keywords' : 'sources';
        if ($mode === 'sources') {
            $reject('keywords_below_min');
            $keywords = [];
            if ($programs === []) {
                return ['panel' => null, 'rejected' => $rejected];
            }
        }

        return [
            'panel' => [
                'mode' => $mode,
                'core' => ['id' => $coreId, 'text' => $sentences[$coreId]],
                'body' => array_values($body),
                'programs' => array_values($programs),
                'keywords' => $keywords,
            ],
            'rejected' => $rejected,
        ];
    }

    /**
     * @param  list<mixed>  $rawKeywords
     * @param  array<string, string>  $sentences
     * @param  array<string, array{id: string, name: string, summary: ?string, source: string}>  $programs  確認を通った制度
     * @return list<array{phrase: string, source_sentence_id: string, programs: list<array{id: string, name: string, summary: ?string}>}>
     */
    private function keywords(array $rawKeywords, array $sentences, array $programs, callable $reject): array
    {
        $forbidden = array_values(array_filter(array_map('strval', (array) config('comparison_commentary.forbidden_words', [])), fn (string $w) => $w !== ''));
        $kwMin = (int) config('top_message_panel.keyword_min_chars');
        $kwMax = (int) config('top_message_panel.keyword_max_chars');
        $keywordsMax = (int) config('top_message_panel.keywords_max');
        $perKeywordMax = (int) config('top_message_panel.programs_per_keyword_max');
        $programsMin = (int) config('top_message_panel.keyword_programs_min');
        $requiredPattern = (string) config('top_message_panel.keyword_required_char_pattern');
        $ignored = array_values(array_map('strval', (array) config('top_message_panel.keyword_ignored_chars')));
        $badEndings = array_values(array_filter(array_map('strval', (array) config('strength_weakness_sentence.quote_bad_endings', [])), fn (string $e) => $e !== ''));

        $keywords = [];
        $usedPhrases = [];
        $usedPrograms = [];
        foreach ($rawKeywords as $keyword) {
            if (! is_array($keyword)) {
                $reject('keyword_malformed');

                continue;
            }
            $phrase = trim((string) ($keyword['phrase'] ?? ''));
            $sourceId = (string) ($keyword['source_sentence_id'] ?? '');

            if (! isset($sentences[$sourceId])) {
                $reject('keyword_source_unknown');

                continue;
            }
            $length = mb_strlen($phrase);
            if ($length < $kwMin || $length > $kwMax) {
                $reject('keyword_length');

                continue;
            }
            if ($requiredPattern !== '' && @preg_match($requiredPattern, $phrase) !== 1) {
                $reject('keyword_no_hiragana');

                continue;
            }
            if (! $this->bracketsBalanced($phrase)) {
                $reject('keyword_unbalanced_brackets');

                continue;
            }
            if (! str_contains($this->loose($sentences[$sourceId], $ignored), $this->loose($phrase, $ignored))) {
                $reject('keyword_not_in_sentence');

                continue;
            }
            foreach ($badEndings as $ending) {
                if (str_ends_with($phrase, $ending)) {
                    $reject('keyword_bad_ending');

                    continue 2;
                }
            }
            if ($this->containsAny($phrase, $forbidden)) {
                $reject('keyword_forbidden_word');

                continue;
            }
            if (isset($usedPhrases[$this->loose($phrase, $ignored)])) {
                $reject('keyword_duplicate');

                continue;
            }

            $cards = [];
            $claimed = [];
            foreach ((array) ($keyword['program_ids'] ?? []) as $programId) {
                $programId = (string) $programId;
                if (! isset($programs[$programId])) {
                    $reject('keyword_program_not_selected');

                    continue;
                }
                if (isset($usedPrograms[$programId]) || isset($claimed[$programId])) {
                    $reject('program_duplicate');

                    continue;
                }
                if (count($cards) >= $perKeywordMax) {
                    $reject('program_over_limit');

                    continue;
                }
                $claimed[$programId] = true;
                $cards[] = ['id' => $programId, 'name' => $programs[$programId]['name'], 'summary' => $programs[$programId]['summary']];
            }

            if (count($cards) < $programsMin) {
                $reject('keyword_programs_below_min');

                continue;
            }
            if (count($keywords) >= $keywordsMax) {
                $reject('keyword_over_limit');

                continue;
            }

            $usedPhrases[$this->loose($phrase, $ignored)] = true;
            foreach ($claimed as $programId => $_) {
                $usedPrograms[$programId] = true;
            }
            $keywords[] = ['phrase' => $phrase, 'source_sentence_id' => $sourceId, 'programs' => $cards];
        }

        return $keywords;
    }

    /**
     * 説明(summary)を確かめ、通ればその文字、通らなければnull(制度名だけのカードにする)。
     */
    private function checkedSummary(string $summary, string $excerpt, callable $reject): ?string
    {
        $summary = trim($summary);
        if ($summary === '') {
            $reject('summary_missing');

            return null;
        }
        $length = mb_strlen($summary);
        if ($length < (int) config('top_message_panel.summary_min_chars') || $length > (int) config('top_message_panel.summary_max_chars')) {
            $reject('summary_length');

            return null;
        }
        if (! str_contains($this->loose($excerpt), $this->loose($summary))) {
            $reject('summary_not_in_excerpt');

            return null;
        }
        foreach ((array) config('strength_weakness_sentence.quote_bad_endings', []) as $ending) {
            if ((string) $ending !== '' && str_ends_with($summary, (string) $ending)) {
                $reject('summary_bad_ending');

                return null;
            }
        }
        if (! $this->bracketsBalanced($summary)) {
            $reject('summary_unbalanced_brackets');

            return null;
        }

        return $summary;
    }

    /**
     * 開き括弧と閉じ括弧の数が、括弧の種類ごとに合っているか(括弧の組は config('top_message_panel.bracket_pairs'))。
     */
    private function bracketsBalanced(string $text): bool
    {
        foreach ((array) config('top_message_panel.bracket_pairs', []) as $pair) {
            [$open, $close] = array_pad(array_values((array) $pair), 2, '');
            if ($open === '' || $close === '') {
                continue;
            }
            if (mb_substr_count($text, (string) $open) !== mb_substr_count($text, (string) $close)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $words
     */
    private function containsAny(string $text, array $words): bool
    {
        foreach ($words as $word) {
            if (mb_stripos($text, $word) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 比較用: 空白を除き、全角半角・大文字小文字の違いを無視する(指定の文字も除く)。表示には使わない。
     *
     * @param  list<string>  $removeChars
     */
    private function loose(string $text, array $removeChars = []): string
    {
        $text = mb_strtolower((string) preg_replace('/[\s\x{3000}\x{00A0}]+/u', '', mb_convert_kana($text, 'asKV')));

        return $removeChars === [] ? $text : str_replace($removeChars, '', $text);
    }
}
