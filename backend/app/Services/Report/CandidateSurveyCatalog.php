<?php

namespace App\Services\Report;

/**
 * 依頼CL-2(2026-10-05): config('brand_wheel_candidate_survey')の読み出し口。
 *
 * 調査の選択肢(options)と、24項目→選択肢の対応表(mapping)を別々に持つ
 * ようにしたため、
 *  - 24項目の側から選択肢を引く(「足りないもの」の各行、従来どおり)
 *  - 選択肢の側から対応する24項目を引く(「求職者が知りたい情報と、自社
 *    サイト」)
 * の両方向を、この1か所に集める。名前・割合の持ち主はoptionsだけであり、
 * ここでは複製しない。対応づけの中身は見直さない(config差し替えのみで
 * 反映できる)。
 */
class CandidateSurveyCatalog
{
    /**
     * 調査の選択肢を、割合の高い順に返す。同率はconfigの記載順を保つ
     * (安定ソート ―― 同じ入力から常に同じ並びになる)。
     *
     * @return list<array{key: string, name: string, percentage: float}>
     */
    public function options(): array
    {
        $rows = [];
        foreach ((array) config('brand_wheel_candidate_survey.options', []) as $key => $option) {
            $rows[] = [
                'key' => (string) $key,
                'name' => (string) ($option['name'] ?? ''),
                'percentage' => (float) ($option['percentage'] ?? 0),
            ];
        }

        $indexed = array_map(null, array_keys($rows), $rows);
        usort($indexed, fn (array $a, array $b) => [$b[1]['percentage'], $a[0]] <=> [$a[1]['percentage'], $b[0]]);

        return array_map(fn (array $pair) => $pair[1], $indexed);
    }

    /**
     * 24項目の1つに対応する調査の選択肢。対応が無い(該当なし)場合や、
     * 対応表が存在しないoptionsのキーを指している場合はnull。
     *
     * @return array{key: string, name: string, percentage: float}|null
     */
    public function optionForSubElement(string $axisKey, string $subKey): ?array
    {
        $optionKey = config("brand_wheel_candidate_survey.mapping.{$axisKey}.{$subKey}.survey_option");
        if (! is_string($optionKey) || $optionKey === '') {
            return null;
        }

        $option = config("brand_wheel_candidate_survey.options.{$optionKey}");
        if (! is_array($option)) {
            return null;
        }

        return [
            'key' => $optionKey,
            'name' => (string) ($option['name'] ?? ''),
            'percentage' => (float) ($option['percentage'] ?? 0),
        ];
    }

    /**
     * 調査の選択肢1つに対応する24項目(config('brand_wheel.axes')の順)。
     *
     * @return list<array{axis_key: string, sub_key: string}>
     */
    public function subElementsForOption(string $optionKey): array
    {
        $result = [];
        foreach ((array) config('brand_wheel.axes') as $axisKey => $axisConfig) {
            foreach (array_keys((array) ($axisConfig['sub_elements'] ?? [])) as $subKey) {
                if (config("brand_wheel_candidate_survey.mapping.{$axisKey}.{$subKey}.survey_option") === $optionKey) {
                    $result[] = ['axis_key' => (string) $axisKey, 'sub_key' => (string) $subKey];
                }
            }
        }

        return $result;
    }
}
