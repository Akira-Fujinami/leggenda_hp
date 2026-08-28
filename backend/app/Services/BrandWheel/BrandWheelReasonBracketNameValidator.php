<?php

namespace App\Services\BrandWheel;

/**
 * 依頼AP-1(2026-08-28): focus_items_reason(改善提案ページの「理由」)は、
 * 自社の項目に言及する際、24項目・6カテゴリ・3領域いずれかの正式名称を
 * 『』で囲む規約になっている(OpenAiBrandWheelImprovementSuggestionProvider::
 * buildPrompt()の【項目名の書き方】参照)。AIがaxis_name(軸名)とsub_name
 * (項目名)を混ぜて存在しない名前を合成してしまう事故を機械的に検知する ――
 * プロンプトの指示だけでは生成が確率的である以上いずれ再発するため
 * (依頼者指摘)。プロンプト側の指示が最初の防波堤、これは最後の防波堤
 * (forbidden_phrases等、既存の二重構成と同じ考え方)。
 *
 * 検知後の扱い(呼び出し元のGenerateBrandWheelImprovementSuggestionJobが行う)は
 * 依頼AF-2/AA-3と同じ方針 ―― 理由のブロックを出さずレポート生成自体は
 * 失敗させず、warningログを1件出す。
 */
class BrandWheelReasonBracketNameValidator
{
    /**
     * @return list<string> 『』で囲まれていたが、24項目・6カテゴリ・3領域の
     *   いずれの正式名称とも一致しなかった文字列(重複除去済み)。実在する
     *   名前しか含まれていない場合、または『』が無い場合は空配列。
     */
    public function invalidBracketedNames(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        if (preg_match_all('/『([^『』]+)』/u', $text, $matches) === false || $matches[1] === []) {
            return [];
        }

        $validNames = $this->validNames();

        return array_values(array_unique(array_filter(
            $matches[1],
            fn (string $name) => ! in_array($name, $validNames, true),
        )));
    }

    /**
     * 24項目(sub_elements)・6カテゴリ(axes.*.name_ja)・3領域(group_labels)の
     * 正式名称をすべて集めたもの。
     *
     * @return list<string>
     */
    private function validNames(): array
    {
        $names = [];

        foreach ((array) config('brand_wheel.axes', []) as $axis) {
            $names[] = (string) ($axis['name_ja'] ?? '');

            foreach ((array) ($axis['sub_elements'] ?? []) as $subName) {
                $names[] = (string) $subName;
            }
        }

        foreach ((array) config('brand_wheel.group_labels', []) as $label) {
            $names[] = (string) $label;
        }

        return array_values(array_filter($names, fn (string $name) => $name !== ''));
    }
}
