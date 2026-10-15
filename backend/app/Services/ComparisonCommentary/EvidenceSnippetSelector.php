<?php

namespace App\Services\ComparisonCommentary;

/**
 * 依頼CUA-1: 根拠の文(保存済みで、原文との突き合わせを通ったもの)から、引用に使う短い一節の候補を、機械的に選ぶ(AIを使わない)。
 *
 *  1. 句点「。」・読点「、」・改行で区切る。節の途中では切らない(つなげない・言い換えない)。区切りの読点・句点・空白は除く。
 *  2. 長さが config の最小〜最大(既定 8〜40字)の節だけを候補にする。
 *  3. 数字・固有の名前(カタカナ語・英字)を含む節を先に、残りを後に、それぞれ元の順で並べる。
 *  4. 候補が無ければ空(その根拠の文は使わない)。
 *
 * どの候補も、根拠の文にそのまま含まれる。閾値・パターンは config('admin_comparison_pptx.strength_weakness_detail.snippet_*')。
 */
class EvidenceSnippetSelector
{
    /**
     * @return list<string> 使う順(先頭が第一候補)
     */
    public function candidates(string $evidence): array
    {
        $min = (int) config('admin_comparison_pptx.strength_weakness_detail.snippet_min_chars');
        $max = (int) config('admin_comparison_pptx.strength_weakness_detail.snippet_max_chars');
        $priorityPattern = (string) config('admin_comparison_pptx.strength_weakness_detail.snippet_priority_pattern');

        $pieces = [];
        foreach (preg_split('/\R/u', $evidence) ?: [] as $line) {
            foreach (preg_split('/(?<=[。、])/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
                $pieces[] = (string) preg_replace('/^[、。\s]+|[、。\s]+$/u', '', $piece);
            }
        }

        $preferred = [];
        $others = [];
        foreach ($pieces as $piece) {
            $length = mb_strlen($piece);
            if ($length < $min || $length > $max) {
                continue;
            }
            if ($priorityPattern !== '' && preg_match($priorityPattern, $piece) === 1) {
                $preferred[] = $piece;
            } else {
                $others[] = $piece;
            }
        }

        return array_values(array_unique(array_merge($preferred, $others)));
    }
}
