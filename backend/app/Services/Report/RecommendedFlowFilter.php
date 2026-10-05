<?php

namespace App\Services\Report;

/**
 * 依頼CN-A3(2026-10-06): 点線の枝(「追加を検討したい導線」)から、実線の枝
 * (メニューの項目)に同じ主題があるものを除く。
 *
 * 点線の導線名は、「足りないもの」に対応するサイトの導線名
 * (config('brand_wheel_candidate_survey.mapping.*.site_flow_name'))で、
 * AdminComparisonPptxDataBuilder::buildRecommendedSiteFlows()が決める。その導線が
 * 対応する調査の選択肢の「メニューの言葉」(config
 * 'admin_comparison_pptx.site_hierarchy_flow_menu_words')のいずれかが、第1階層の
 * メニューの言葉(畳まれて画面に出ない枝を含む)に含まれていれば除く。調査の選択肢に
 * 対応しない導線(「サステナビリティ」等)は、導線名そのものがメニューの言葉に
 * 含まれる(または含む)ときに除く。部分一致、大文字小文字・全角半角を区別しない。
 * AIは使わない。
 */
class RecommendedFlowFilter
{
    /**
     * @param  list<array{name: string, option: ?string}>  $flows
     * @param  list<string>  $menuLabels  第1階層のすべての名前
     * @return list<string> 点線に残す導線名
     */
    public function filter(array $flows, array $menuLabels): array
    {
        $labels = array_map(fn (string $label) => $this->normalize($label), $menuLabels);
        $words = (array) config('admin_comparison_pptx.site_hierarchy_flow_menu_words', []);

        $kept = [];
        foreach ($flows as $flow) {
            if (! $this->isCoveredByMenu($flow, $labels, $words)) {
                $kept[] = $flow['name'];
            }
        }

        return $kept;
    }

    /**
     * @param  array{name: string, option: ?string}  $flow
     * @param  list<string>  $labels  正規化済み
     * @param  array<string, list<string>>  $words
     */
    private function isCoveredByMenu(array $flow, array $labels, array $words): bool
    {
        $name = $this->normalize($flow['name']);
        $candidates = [];
        if ($flow['option'] !== null) {
            foreach ((array) ($words[$flow['option']] ?? []) as $word) {
                $candidates[] = $this->normalize((string) $word);
            }
        }

        foreach ($labels as $label) {
            if ($label === '') {
                continue;
            }
            if ($name !== '' && (str_contains($label, $name) || (mb_strlen($label) >= 2 && str_contains($name, $label)))) {
                return true;
            }
            foreach ($candidates as $word) {
                if ($word !== '' && str_contains($label, $word)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** 大文字小文字・全角半角の差と、空白を無視するための正規化。 */
    private function normalize(string $text): string
    {
        return str_replace([' ', '　'], '', mb_strtolower(mb_convert_kana($text, 'asKV')));
    }
}
