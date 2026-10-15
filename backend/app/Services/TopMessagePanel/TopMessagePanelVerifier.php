<?php

namespace App\Services\TopMessagePanel;

/**
 * 依頼CZ-3: AIが選んだものを、サーバー側で材料と突き合わせる。通ったものだけをパネルに使う
 * (プロンプトは確認の代わりではない。確認を緩めて通さないこと)。長さ・個数・語の一覧はすべて config。
 *
 *  - 核の一文: IDが実在し、文の長さが core_min_chars〜core_max_chars。通らなければパネルは使わない
 *  - 本文: IDが実在し、核と重複しない(同じIDの重複も落とす)。body_maxを超える分は落とす。足りなければ本文なしでよい
 *  - キーワード: phraseがsource_sentence_idの文にそのまま含まれる(空白・全角半角の違いだけ無視)。keyword_min_chars〜keyword_max_chars。
 *               禁止の言葉(依頼CUと同じ一覧)を含まない。同じ語句の重複は落とす
 *  - 制度: IDが実在し、他のキーワードと重複しない。1キーワードにprograms_per_keyword_maxまで
 *  - 制度の説明: summaryがその制度の抜粋にそのまま含まれる。summary_min_chars〜summary_max_chars。末尾が
 *               config('strength_weakness_sentence.quote_bad_endings')(依頼CWと同じ)で終わらない。
 *               説明だけが落ちた制度は、制度名だけのカード(summary=null)にする(落とさない)
 *  - 制度が1件も付かないキーワードは捨てる。キーワードがkeywords_min個に満たなければ、パネルは使わない
 *
 * 落とした理由は符号ごとの件数で返す(文の中身は返さない・ログにも出さない)。
 */
class TopMessagePanelVerifier
{
    /**
     * @param  array<string, mixed>  $decoded  AIの出力
     * @param  array{sentences: list<array{id: string, text: string}>, programs: list<array{id: string, name: string, excerpt: string, source: string}>}  $material
     * @return array{panel: ?array{core: array{id: string, text: string}, body: list<array{id: string, text: string}>, keywords: list<array{phrase: string, source_sentence_id: string, programs: list<array{id: string, name: string, summary: ?string}>}>}, rejected: array<string, int>}
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
        $programs = [];
        foreach ($material['programs'] as $program) {
            $programs[$program['id']] = $program;
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

        // キーワード
        $forbidden = array_values(array_filter(array_map('strval', (array) config('comparison_commentary.forbidden_words', [])), fn (string $w) => $w !== ''));
        $kwMin = (int) config('top_message_panel.keyword_min_chars');
        $kwMax = (int) config('top_message_panel.keyword_max_chars');
        $keywordsMax = (int) config('top_message_panel.keywords_max');
        $perKeywordMax = (int) config('top_message_panel.programs_per_keyword_max');

        $keywords = [];
        $usedPhrases = [];
        $usedPrograms = [];
        foreach ((array) ($decoded['keywords'] ?? []) as $keyword) {
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
            $phraseLength = mb_strlen($phrase);
            if ($phraseLength < $kwMin || $phraseLength > $kwMax) {
                $reject('keyword_length');

                continue;
            }
            if (! str_contains($this->loose($sentences[$sourceId]), $this->loose($phrase))) {
                $reject('keyword_not_in_sentence');

                continue;
            }
            if ($this->containsAny($phrase, $forbidden)) {
                $reject('keyword_forbidden_word');

                continue;
            }
            if (isset($usedPhrases[$this->loose($phrase)])) {
                $reject('keyword_duplicate');

                continue;
            }

            $cards = [];
            $claimed = [];
            foreach ((array) ($keyword['programs'] ?? []) as $program) {
                $programId = is_array($program) ? (string) ($program['program_id'] ?? '') : '';
                if (! isset($programs[$programId])) {
                    $reject('program_unknown_id');

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
                $summary = $this->checkedSummary((string) ($program['summary'] ?? ''), $programs[$programId]['excerpt'], $reject);
                $cards[] = ['id' => $programId, 'name' => $programs[$programId]['name'], 'summary' => $summary];
            }

            if ($cards === []) {
                $reject('keyword_no_program');

                continue;
            }
            if (count($keywords) >= $keywordsMax) {
                $reject('keyword_over_limit');

                continue;
            }

            $usedPhrases[$this->loose($phrase)] = true;
            foreach ($claimed as $programId => $_) {
                $usedPrograms[$programId] = true;
            }
            $keywords[] = ['phrase' => $phrase, 'source_sentence_id' => $sourceId, 'programs' => $cards];
        }

        if (count($keywords) < (int) config('top_message_panel.keywords_min')) {
            $reject('keywords_below_min');

            return ['panel' => null, 'rejected' => $rejected];
        }

        return [
            'panel' => [
                'core' => ['id' => $coreId, 'text' => $sentences[$coreId]],
                'body' => array_values($body),
                'keywords' => $keywords,
            ],
            'rejected' => $rejected,
        ];
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

        return $summary;
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

    /** 比較用: 空白を除き、全角半角・大文字小文字の違いを無視する。表示には使わない。 */
    private function loose(string $text): string
    {
        return mb_strtolower((string) preg_replace('/[\s\x{3000}\x{00A0}]+/u', '', mb_convert_kana($text, 'asKV')));
    }
}
