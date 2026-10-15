<?php

namespace App\Services\Report;

/**
 * 依頼CT-1: ブランド・ホイール比較のスライドの見出しの下に置く「強み」「弱み」の一文。
 * 6領域の点数(自社と、競合の最高)から決まった形で作る(AIは使わない。同じ入力から必ず同じ文になる)。
 * 文言はすべてconfig('admin_comparison_pptx.strength_weakness_*')。「ありません」「発信していない」のような
 * 断定はしない。
 *
 * 強み: (1) 自社が競合の最高を上回る領域のうち差が最大の領域 (2) 無ければ、競合の最高と並ぶ領域のうち自社の点数が最も高い領域
 *       (3) それも無ければ、自社の中で点数が最も高い領域。
 * 弱み: (1) 競合の最高を下回る領域のうち差が最大の領域(最高点の会社名つき) (2) 無ければ「すべての領域で…」。
 * 同点のときは、比較表の領域の並び順で先のものを選ぶ。
 *
 * 依頼CHの「件数での比較に適さない」会社(自社・競合)が1社でもあるときは、強み・弱みは出さず、1行の文言だけ。
 * 自社の判定が成立していない(self_readable=false)とき、競合がいないときは、何も出さない(null)。
 *
 * 依頼CV: AIが書いて、サーバー側の確認(StrengthWeaknessSentenceVerifier)を通った一文($commentary、
 * StrengthWeaknessSentenceStoreのファイル)があるときは、その側をAIの文に置き換える(強み・弱みそれぞれ。落ちた側・対象が無い側は
 * nullなので、上のCT-1の文のまま)。$detail(依頼CUAの一節の文、AIを使わない)は引数として残してあるが、比較ページの出力経路では
 * 渡さない(一節が会社名だけの切れ端になったため)。
 * 注記: ai_used=AIの文を使った、detail_used=$detailの文を使った(スライドの注記・文字の大きさに使う)。
 * 点数による「読み取れた記述が少ない」の判定は、これらより先に効く。
 */
class ComparisonStrengthWeaknessComposer
{
    /**
     * @param  list<array{name: string, matched: int, total: int, is_self: bool, material_sufficient: bool}>  $companies  先頭が自社
     * @param  list<array{name: string, caption: ?string, denominator: int, self_count: int, competitor_counts: list<int>, self_gap: bool}>  $axes
     * @param  ?array<string, mixed>  $commentary  ComparisonCommentaryStoreが読んだ結果(strength/weaknessは確認を通った文かnull)
     * @param  ?array{strength: ?string, weakness: ?string}  $detail  StrengthWeaknessDetailComposerの文(AIを使わない)
     * @return ?array{notice: ?string, strength: ?string, weakness: ?string, ai_used: bool, detail_used: bool}
     */
    public function compose(array $companies, array $axes, bool $selfReadable, ?array $commentary = null, ?array $detail = null): ?array
    {
        $competitors = array_values(array_filter($companies, fn (array $c) => ! $c['is_self']));
        if (! $selfReadable || $competitors === [] || $axes === []) {
            return null;
        }

        foreach ($companies as $company) {
            if (! ($company['material_sufficient'] ?? true)) {
                return ['notice' => (string) config('admin_comparison_pptx.strength_weakness_insufficient'), 'strength' => null, 'weakness' => null, 'ai_used' => false, 'detail_used' => false];
            }
        }

        $rows = [];
        foreach ($axes as $index => $axis) {
            $best = $axis['competitor_counts'] === [] ? 0 : max($axis['competitor_counts']);
            $rows[] = ['index' => $index, 'axis' => $axis, 'self' => (int) $axis['self_count'], 'best' => $best];
        }

        $detailStrength = $this->aiSentence($detail, 'strength');
        $detailWeakness = $this->aiSentence($detail, 'weakness');
        $aiStrength = $this->aiSentence($commentary, 'strength');
        $aiWeakness = $this->aiSentence($commentary, 'weakness');

        return [
            'notice' => null,
            'strength' => $aiStrength ?? $detailStrength ?? $this->strength($rows, count($competitors)),
            'weakness' => $aiWeakness ?? $detailWeakness ?? $this->weakness($rows, $competitors),
            'ai_used' => $aiStrength !== null || $aiWeakness !== null,
            'detail_used' => ($aiStrength === null && $detailStrength !== null) || ($aiWeakness === null && $detailWeakness !== null),
        ];
    }

    /**
     * @param  ?array<string, mixed>  $source  CUのファイル(['weakness' => ['sentence' => ...]])か、CUAの文(['weakness' => '...'])
     */
    private function aiSentence(?array $source, string $side): ?string
    {
        $value = $source[$side] ?? null;
        $sentence = is_array($value) ? ($value['sentence'] ?? null) : $value;

        return is_string($sentence) && trim($sentence) !== '' ? trim($sentence) : null;
    }

    /**
     * @param  list<array{index: int, axis: array<string, mixed>, self: int, best: int}>  $rows
     */
    private function strength(array $rows, int $competitorCount): string
    {
        $over = array_filter($rows, fn (array $r) => $r['self'] > $r['best']);
        if ($over !== []) {
            $row = $this->pick($over, fn (array $r) => $r['self'] - $r['best']);

            return sprintf((string) config('admin_comparison_pptx.strength_weakness_strength_over'), $row['axis']['name'], $row['self'], $row['axis']['denominator'], $competitorCount, $row['best']);
        }

        // 並ぶ領域: 0点どうしの「並び」は強みとして出さない。
        $equal = array_filter($rows, fn (array $r) => $r['self'] === $r['best'] && $r['self'] > 0);
        if ($equal !== []) {
            $row = $this->pick($equal, fn (array $r) => $r['self']);

            return sprintf((string) config('admin_comparison_pptx.strength_weakness_strength_equal'), $row['axis']['name'], $row['self'], $row['axis']['denominator']);
        }

        $row = $this->pick($rows, fn (array $r) => $r['self']);

        return sprintf((string) config('admin_comparison_pptx.strength_weakness_strength_self_top'), $row['axis']['name'], $row['self'], $row['axis']['denominator']);
    }

    /**
     * @param  list<array{index: int, axis: array<string, mixed>, self: int, best: int}>  $rows
     * @param  list<array{name: string}>  $competitors
     */
    private function weakness(array $rows, array $competitors): string
    {
        $below = array_filter($rows, fn (array $r) => $r['self'] < $r['best']);
        if ($below === []) {
            return (string) config('admin_comparison_pptx.strength_weakness_weakness_none');
        }

        $row = $this->pick($below, fn (array $r) => $r['best'] - $r['self']);

        // 最高点の会社(比較表の見出しと同じ呼び方: 競合が config 以下の社数なら企業名、それを超えると記号)。
        $useNames = count($competitors) <= (int) config('admin_comparison_pptx.wheel_names_max_competitors');
        $symbols = (string) config('admin_comparison_pptx.wheel_competitor_symbols');
        $labels = [];
        foreach ($row['axis']['competitor_counts'] as $i => $count) {
            if ($count === $row['best']) {
                $labels[] = $useNames ? (string) $competitors[$i]['name'] : (mb_substr($symbols, $i, 1) !== '' ? mb_substr($symbols, $i, 1) : (string) ($i + 1));
            }
        }
        $who = $labels[0].(count($labels) > 1 ? (string) config('admin_comparison_pptx.strength_weakness_names_more') : '');

        return sprintf((string) config('admin_comparison_pptx.strength_weakness_weakness_gap'), $row['axis']['name'], $row['self'], $row['axis']['denominator'], $row['best'], $who);
    }

    /**
     * 指標が最大の行。同点は領域の並び順(index)で先のもの。
     *
     * @param  array<int, array{index: int, axis: array<string, mixed>, self: int, best: int}>  $rows
     * @return array{index: int, axis: array<string, mixed>, self: int, best: int}
     */
    private function pick(array $rows, callable $score): array
    {
        $chosen = null;
        foreach ($rows as $row) {
            if ($chosen === null || $score($row) > $score($chosen) || ($score($row) === $score($chosen) && $row['index'] < $chosen['index'])) {
                $chosen = $row;
            }
        }

        return $chosen;
    }
}
