<?php

namespace App\Services\StrengthWeaknessSentence;

/**
 * 依頼CW-2: 強み・弱みの一文を、サーバーが決まった形で組み立てる(AIは文を書かず、引用する一節(quote)を選ぶだけ)。
 * 形は config の文言(強み・弱みの形は依頼CU追補と同じ admin_comparison_pptx.strength_weakness_detail、
 * 求職者調査の割合で選んだ強みの形は strength_weakness_sentence.strength_survey_template)。
 *
 *  強み(自社だけの強み・半数ちょうど): 「{項目名}は、競合{N}社のうち{M}社からしか読み取れなかった内容で、自社は「{引用}」と記載しています。」
 *     (M=0 のときは「競合{N}社のいずれからも読み取れなかった内容で」)
 *  強み(求職者調査の割合): 「求職者の{割合}%が知りたい{調査項目}について、自社は「{引用}」と記載しています。」
 *  弱み: 「{項目名}は自社サイトから読み取れませんでしたが、{競合}は「{引用}」と記載しています。」
 *
 * 「評価」「求職者に何が伝わるか」の一言は足さない。項目名は材料(config('brand_wheel.axes')のsub_elements)の表記のまま。
 */
class StrengthWeaknessSentenceAssembler
{
    /**
     * @param  'strength'|'weakness'  $side
     * @param  array<string, mixed>  $target  材料のその側(strength / weakness)
     */
    public function assemble(string $side, array $target, string $quote): string
    {
        $detail = 'admin_comparison_pptx.strength_weakness_detail.';

        if ($side === 'weakness') {
            return sprintf((string) config($detail.'weakness_template'), $target['name'], $target['company'], $quote);
        }

        if (($target['basis'] ?? null) === 'survey' && is_array($target['survey'] ?? null)) {
            return sprintf((string) config('strength_weakness_sentence.strength_survey_template'), $this->percentage((float) $target['survey']['percentage']), $target['survey']['name'], $quote);
        }

        $matched = (int) $target['matched'];

        return $matched === 0
            ? sprintf((string) config($detail.'strength_none_template'), $target['name'], $target['competitor_count'], $quote)
            : sprintf((string) config($detail.'strength_template'), $target['name'], $target['competitor_count'], $matched, $quote);
    }

    /** 調査の割合(23.8 → "23.8"、17.0 → "17")。 */
    private function percentage(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
