<?php

namespace App\Services\TopMessagePanel;

/**
 * 依頼DD-2: パネルの中身を、AIを使わずに機械で選ぶ(同じ入力なら同じ結果。通信しない)。
 * ページの型(パネル)は、AIの選択のときと同じ。ここは、その型に流し込む中身だけを作る。
 *
 * 左(メッセージ): 核の一文 = 考え・意志を表す言葉(config)を含む最初の文(無ければ最初の文)。
 *   本文 = 核の一文の前後の文から合わせてN文(ページ上の順番のまま)。
 * 右(制度): 出典のページ名ごとの帯。帯の順番は制度の数が多い順(同数はページの順)・最大N本・帯の中は最大Nカード。
 *   カードの説明 = 制度の抜粋の最初の文(句点まで)が上限以内ならそれ。超える・句点で終わらず切れている・括弧が閉じていないときは、名前だけ
 *   (文の途中で切らない)。
 *
 * 材料はTopMessagePanelMaterialBuilder::build()の戻り値(依頼DAの整理を通ったもの)。語・上限はconfig('top_message_panel')。
 */
class TopMessagePanelMachineSelector
{
    /**
     * @param  array<string, mixed>  $material  TopMessagePanelMaterialBuilder::build()の戻り値
     * @return array{selection: string, speaker: ?string, source_title: ?string, core: ?string, body: list<string>, mode: string, programs: list<array{name: string, summary: ?string, source: string}>, keywords: list<mixed>}
     */
    public function select(array $material): array
    {
        $sentences = array_values(array_map(fn (array $row) => (string) $row['text'], (array) ($material['sentences'] ?? [])));
        $coreIndex = $this->coreIndex($sentences);

        return [
            'selection' => 'machine',
            'speaker' => $material['speaker'] ?? null,
            'source_title' => $material['message_title'] ?? null,
            'core' => $coreIndex === null ? null : $sentences[$coreIndex],
            'body' => $coreIndex === null ? [] : $this->body($sentences, $coreIndex),
            'mode' => 'sources',
            'programs' => $this->programs((array) ($material['programs'] ?? [])),
            'keywords' => [],
        ];
    }

    /**
     * @param  list<string>  $sentences
     */
    private function coreIndex(array $sentences): ?int
    {
        if ($sentences === []) {
            return null;
        }
        $words = array_values(array_filter(array_map('strval', (array) config('top_message_panel.machine_core_words')), fn (string $w) => $w !== ''));
        foreach ($sentences as $i => $sentence) {
            foreach ($words as $word) {
                if (mb_strpos($sentence, $word) !== false) {
                    return $i;
                }
            }
        }

        return 0;
    }

    /**
     * 核の一文の後ろ・前の順に1文ずつ取る(片側が無ければ、もう片側から)。ページ上の順番に並べて返す。
     *
     * @param  list<string>  $sentences
     * @return list<string>
     */
    private function body(array $sentences, int $coreIndex): array
    {
        $count = max(0, (int) config('top_message_panel.machine_body_count'));
        $picked = [];
        $after = $coreIndex + 1;
        $before = $coreIndex - 1;
        while (count($picked) < $count && ($after < count($sentences) || $before >= 0)) {
            if ($after < count($sentences)) {
                $picked[] = $after++;
            }
            if (count($picked) < $count && $before >= 0) {
                $picked[] = $before--;
            }
        }
        sort($picked);

        return array_map(fn (int $i) => $sentences[$i], $picked);
    }

    /**
     * @param  list<array{name: string, excerpt: string, source: string}>  $programs
     * @return list<array{name: string, summary: ?string, source: string}>
     */
    private function programs(array $programs): array
    {
        $bands = [];
        foreach ($programs as $program) {
            $source = (string) ($program['source'] ?? '');
            $bands[$source][] = [
                'name' => (string) $program['name'],
                'summary' => $this->summary((string) ($program['excerpt'] ?? '')),
                'source' => $source,
            ];
        }

        // 制度の数が多い順(同数はページの順のまま)。
        $order = array_keys($bands);
        usort($order, fn ($a, $b) => count($bands[$b]) <=> count($bands[$a]) ?: array_search($a, array_keys($bands), true) <=> array_search($b, array_keys($bands), true));

        $perBand = (int) config('top_message_panel.programs_per_keyword_max');
        $flat = [];
        foreach (array_slice($order, 0, (int) config('top_message_panel.source_bands_max')) as $source) {
            foreach (array_slice($bands[$source], 0, $perBand) as $row) {
                $flat[] = $row;
            }
        }

        return $flat;
    }

    /**
     * 抜粋の最初の文(句点まで)。上限を超える・最後まで書かれていない・括弧が閉じていないときはnull(名前だけのカード)。
     */
    private function summary(string $excerpt): ?string
    {
        $excerpt = trim($excerpt);
        if ($excerpt === '') {
            return null;
        }

        $ends = array_values(array_filter(array_map('strval', (array) config('top_message_panel.machine_sentence_end_chars')), fn (string $c) => $c !== ''));
        $cut = null;
        foreach ($ends as $end) {
            $pos = mb_strpos($excerpt, $end);
            if ($pos !== false && ($cut === null || $pos + mb_strlen($end) < $cut)) {
                $cut = $pos + mb_strlen($end);
            }
        }

        if ($cut !== null) {
            $first = mb_substr($excerpt, 0, $cut);
        } else {
            // 句点が無い: 途中で切れた抜粋(末尾が「…」など)は、最後まで書かれた文ではないので使わない。
            foreach ((array) config('top_message_panel.machine_cut_suffixes') as $suffix) {
                if ((string) $suffix !== '' && str_ends_with($excerpt, (string) $suffix)) {
                    return null;
                }
            }
            $first = $excerpt;
        }

        if (mb_strlen($first) > (int) config('top_message_panel.machine_summary_max_chars') || ! $this->bracketsBalanced($first)) {
            return null;
        }

        return $first;
    }

    private function bracketsBalanced(string $text): bool
    {
        foreach ((array) config('top_message_panel.bracket_pairs') as $pair) {
            if (mb_substr_count($text, (string) $pair[0]) !== mb_substr_count($text, (string) $pair[1])) {
                return false;
            }
        }

        return true;
    }
}
