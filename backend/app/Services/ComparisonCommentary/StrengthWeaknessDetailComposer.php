<?php

namespace App\Services\ComparisonCommentary;

use App\Models\Analysis;

/**
 * 依頼CUA-2/3: 強み・弱みの一文を、「項目」と「根拠の一節」で書く(AIを使わない。同じ入力から必ず同じ文になる)。
 * 材料は ComparisonCommentaryMaterialBuilder(24項目の○×と根拠の文)。一節は EvidenceSnippetSelector が機械的に選び、
 * そのまま(言い換えず・つなげず)文に入れる。自社が×の項目は「読み取れませんでした」と書く(「無い」「書いていない」と書かない)。
 *
 * 強み(上から順に、最初に作れたもの):
 *   1. 自社だけの強み(自社○・競合の○が半数未満): 競合の○が少ない項目から(同数は項目の並び順)
 *   2. 自社○で、競合の○が半数ちょうどの項目(同じ形)
 *   3. 全社が同点の領域(0点どうしは除く): 「差がつきにくい」
 *   4. 無ければ null(依頼CT-1の一文に戻る)
 * 弱み: 自社の穴(自社×・競合の○が半数以上): 競合の○が多い項目から(同数は並び順)。競合は、その項目が○で一節が選べる
 *   最初の会社(比較表の並び順)。無ければ null(依頼CT-1の一文)。
 *
 * 一節が選べない項目は飛ばす。文が上限(config、既定100字)を超えるときは、同じ根拠の文の別の候補(短いもの)に替え、
 * それでも超えるなら次の項目、最後は null。文言・閾値はすべて config('admin_comparison_pptx.strength_weakness_detail')。
 * 材料を作れない(読み取れた記述が少ない会社がある等)ときは何も作らない(null)。
 *
 * 依頼CV: 本番で一節が会社名だけの切れ端になったため、この文(compose)は比較ページの出力経路から外した
 * (config('admin_comparison_pptx.strength_weakness_detail.enabled')の既定はfalse)。項目・会社の選び方(pick)は、
 * 依頼CVの AI の材料に使う。
 */
class StrengthWeaknessDetailComposer
{
    public function __construct(
        private readonly ComparisonCommentaryMaterialBuilder $materials,
        private readonly EvidenceSnippetSelector $snippets,
    ) {}

    /**
     * @return array{strength: ?string, weakness: ?string, info: array<string, mixed>}
     */
    public function composeForAnalysis(Analysis $analysis): array
    {
        return $this->compose($this->materials->build($analysis));
    }

    /**
     * 依頼CV-1: 取り上げる「1項目・1社」を、依頼CU追補の決まりのまま機械が選ぶ(一節は選ばない。文も作らない)。
     *  強み: 自社だけの強み(自社○・競合の○が半数未満)を、競合の○が少ない順(同数は項目の並び順)に、無ければ
     *        自社○で競合の○が半数ちょうどの項目。自社の根拠の文が空の項目は飛ばす。
     *  弱み: 自社の穴(自社×・競合の○が半数以上)を、競合の○が多い順(同数は項目の並び順)に。会社は、その項目が○で
     *        根拠の文がある競合のうち、比較表の並び順で最初の会社。
     * 対象が無い側は null(依頼CT-1の文)。
     *
     * @param  array<string, mixed>  $material  ComparisonCommentaryMaterialBuilder::build()の戻り値
     * @return array{strength: ?array{item: array<string, mixed>, kind: string}, weakness: ?array{item: array<string, mixed>, company: array<string, mixed>}}
     */
    public function pick(array $material): array
    {
        $none = ['strength' => null, 'weakness' => null];
        if (($material['status'] ?? null) !== 'ok') {
            return $none;
        }

        $n = (int) $material['competitor_count'];
        $half = $n * (float) config('comparison_commentary.majority_ratio', 0.5);

        $under = [];
        $exactHalf = [];
        $holes = [];
        foreach ((array) $material['items'] as $item) {
            $count = (int) $item['competitor_matched_count'];
            if ($item['self_matched']) {
                if (trim((string) $item['self_evidence']) === '') {
                    continue;
                }
                if ($count < $half) {
                    $under[] = $item;
                } elseif (abs($count - $half) < 1e-9) {
                    $exactHalf[] = $item;
                }
            } elseif ($count > 0 && $count >= $half) {
                $holes[] = $item;
            }
        }
        $byCountThenOrder = fn (array $a, array $b) => [$a['competitor_matched_count'], $a['order']] <=> [$b['competitor_matched_count'], $b['order']];
        usort($under, $byCountThenOrder);
        usort($exactHalf, $byCountThenOrder);
        usort($holes, fn (array $a, array $b) => [$b['competitor_matched_count'], $a['order']] <=> [$a['competitor_matched_count'], $b['order']]);

        $strength = null;
        if ($under !== []) {
            $strength = ['item' => $under[0], 'kind' => 'only'];
        } elseif ($exactHalf !== []) {
            $strength = ['item' => $exactHalf[0], 'kind' => 'half'];
        }

        $weakness = null;
        foreach ($holes as $item) {
            foreach ((array) $item['competitors'] as $competitor) {
                if ($competitor['matched'] && trim((string) $competitor['evidence']) !== '') {
                    $weakness = ['item' => $item, 'company' => $competitor];

                    break 2;
                }
            }
        }

        return ['strength' => $strength, 'weakness' => $weakness];
    }

    /**
     * @param  array<string, mixed>  $material  ComparisonCommentaryMaterialBuilder::build()の戻り値
     * @return array{strength: ?string, weakness: ?string, info: array<string, mixed>}
     */
    public function compose(array $material): array
    {
        $empty = ['strength' => null, 'weakness' => null, 'info' => ['strength_item' => null, 'weakness_item' => null, 'strength_skipped' => 0, 'weakness_skipped' => 0, 'strength_kind' => null]];
        if (! (bool) config('admin_comparison_pptx.strength_weakness_detail.enabled') || ($material['status'] ?? null) !== 'ok') {
            return $empty;
        }

        $strength = $this->strength($material);
        $weakness = $this->weakness($material);

        return [
            'strength' => $strength['sentence'],
            'weakness' => $weakness['sentence'],
            'info' => [
                'strength_item' => $strength['item'],
                'weakness_item' => $weakness['item'],
                'strength_skipped' => $strength['skipped'],
                'weakness_skipped' => $weakness['skipped'],
                'strength_kind' => $strength['kind'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $material
     * @return array{sentence: ?string, item: ?string, skipped: int, kind: ?string}
     */
    private function strength(array $material): array
    {
        $n = (int) $material['competitor_count'];
        $half = $n * (float) config('comparison_commentary.majority_ratio', 0.5);

        $under = [];
        $exactHalf = [];
        foreach ((array) $material['items'] as $item) {
            if (! $item['self_matched']) {
                continue;
            }
            $count = (int) $item['competitor_matched_count'];
            if ($count < $half) {
                $under[] = $item;
            } elseif (abs($count - $half) < 1e-9) {
                $exactHalf[] = $item;
            }
        }
        $byCountThenOrder = fn (array $a, array $b) => [$a['competitor_matched_count'], $a['order']] <=> [$b['competitor_matched_count'], $b['order']];
        usort($under, $byCountThenOrder);
        usort($exactHalf, $byCountThenOrder);

        $skipped = 0;
        foreach (['only' => $under, 'half' => $exactHalf] as $kind => $items) {
            foreach ($items as $item) {
                $sentence = $this->firstFitting($this->snippets->candidates((string) $item['self_evidence']), fn (string $snippet) => $this->strengthSentence($item, $n, $snippet));
                if ($sentence !== null) {
                    return ['sentence' => $sentence, 'item' => (string) $item['key'], 'skipped' => $skipped, 'kind' => $kind];
                }
                $skipped++;
            }
        }

        // 全社が同点の領域(0点どうしは強みとして出さない)。点が高い領域、同点は領域の並び順。
        $ties = array_values(array_filter((array) $material['tie_axes'], fn (array $t) => (int) $t['score'] > 0));
        usort($ties, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        foreach ($ties as $tie) {
            $sentence = sprintf((string) config('admin_comparison_pptx.strength_weakness_detail.strength_tie_template'), $tie['axis'], $tie['score'], $tie['denominator']);
            if ($this->fits($sentence)) {
                return ['sentence' => $sentence, 'item' => null, 'skipped' => $skipped, 'kind' => 'tie'];
            }
        }

        return ['sentence' => null, 'item' => null, 'skipped' => $skipped, 'kind' => null];
    }

    /**
     * @param  array<string, mixed>  $material
     * @return array{sentence: ?string, item: ?string, skipped: int}
     */
    private function weakness(array $material): array
    {
        $n = (int) $material['competitor_count'];
        $half = $n * (float) config('comparison_commentary.majority_ratio', 0.5);

        $holes = array_values(array_filter((array) $material['items'], fn (array $i) => ! $i['self_matched'] && $i['competitor_matched_count'] > 0 && $i['competitor_matched_count'] >= $half));
        usort($holes, fn (array $a, array $b) => [$b['competitor_matched_count'], $a['order']] <=> [$a['competitor_matched_count'], $b['order']]);

        $competitorsByLabel = array_column((array) $material['competitors'], 'name', 'label');
        $skipped = 0;
        foreach ($holes as $item) {
            // その項目が○で、一節が選べる最初の会社(比較表の並び順)。
            foreach ((array) $item['competitors'] as $competitor) {
                if (! $competitor['matched']) {
                    continue;
                }
                $candidates = $this->snippets->candidates((string) $competitor['evidence']);
                if ($candidates === []) {
                    continue;
                }
                $label = (string) $competitor['label'];
                $who = ($competitorsByLabel[$label] ?? $label) === $label ? $label : sprintf((string) config('admin_comparison_pptx.strength_weakness_detail.symbol_label_template'), $label);
                $sentence = $this->firstFitting($candidates, fn (string $snippet) => sprintf((string) config('admin_comparison_pptx.strength_weakness_detail.weakness_template'), $item['name'], $who, $snippet));
                if ($sentence !== null) {
                    return ['sentence' => $sentence, 'item' => (string) $item['key'], 'skipped' => $skipped];
                }

                break;
            }
            $skipped++;
        }

        return ['sentence' => null, 'item' => null, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function strengthSentence(array $item, int $competitorCount, string $snippet): string
    {
        $matched = (int) $item['competitor_matched_count'];
        $template = $matched === 0
            ? (string) config('admin_comparison_pptx.strength_weakness_detail.strength_none_template')
            : (string) config('admin_comparison_pptx.strength_weakness_detail.strength_template');

        return $matched === 0
            ? sprintf($template, $item['name'], $competitorCount, $snippet)
            : sprintf($template, $item['name'], $competitorCount, $matched, $snippet);
    }

    /**
     * 候補を順に試して、上限以内に収まる最初の文を返す(無ければ null)。
     *
     * @param  list<string>  $candidates
     * @param  callable(string): string  $build
     */
    private function firstFitting(array $candidates, callable $build): ?string
    {
        // 短い候補で替えられるよう、先頭(優先)の候補、次に短い順。
        $ordered = $candidates === [] ? [] : array_merge([$candidates[0]], $this->shortestFirst(array_slice($candidates, 1)));
        foreach ($ordered as $snippet) {
            $sentence = $build($snippet);
            if ($this->fits($sentence)) {
                return $sentence;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function shortestFirst(array $candidates): array
    {
        usort($candidates, fn (string $a, string $b) => mb_strlen($a) <=> mb_strlen($b));

        return $candidates;
    }

    private function fits(string $sentence): bool
    {
        return mb_strlen($sentence) <= (int) config('admin_comparison_pptx.strength_weakness_detail.sentence_limit_chars');
    }
}
