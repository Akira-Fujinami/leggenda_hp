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
        $labels = array_map(fn (string $label) => ['compact' => $this->normalize($label), 'spaced' => $this->normalizeKeepingSpaces($label)], $menuLabels);
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
     * @param  list<array{compact: string, spaced: string}>  $labels
     * @param  array<string, list<string>>  $words
     */
    private function isCoveredByMenu(array $flow, array $labels, array $words): bool
    {
        $name = $this->normalize($flow['name']);
        $candidates = [];
        if ($flow['option'] !== null) {
            foreach ((array) ($words[$flow['option']] ?? []) as $word) {
                $candidates[] = (string) $word;
            }
        }

        foreach ($labels as $label) {
            if ($label['compact'] === '') {
                continue;
            }
            if ($name !== '' && (str_contains($label['compact'], $name) || (mb_strlen($label['compact']) >= 2 && str_contains($name, $label['compact'])))) {
                return true;
            }
            foreach ($candidates as $word) {
                if ($this->labelHasWord($label, $word)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 依頼CO-5: 英数字だけの言葉は語の境界で照合する(前後が英数字でないこと。語末の複数形の
     * s・esは許す ―― `job`は`jobs`に当たるが、`history`の`story`や`agriculture`の`culture`には
     * 当たらない)。日本語を含む言葉は従来どおり部分一致。
     *
     * @param  array{compact: string, spaced: string}  $label
     */
    private function labelHasWord(array $label, string $word): bool
    {
        $compact = $this->normalize($word);
        if ($compact === '') {
            return false;
        }

        $spacedWord = $this->normalizeKeepingSpaces($word);
        if (preg_match('/^[a-z0-9 ]+$/', $spacedWord) === 1) {
            return preg_match('/(?<![a-z0-9])'.preg_quote($spacedWord, '/').'(?:e?s)?(?![a-z0-9])/', $label['spaced']) === 1;
        }

        return str_contains($label['compact'], $compact);
    }

    /** 語の境界の判定用: 大文字小文字・全角半角を揃え、空白は1つにまとめて残す。 */
    private function normalizeKeepingSpaces(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(mb_convert_kana($text, 'asKV'))));
    }

    /** 大文字小文字・全角半角の差と、空白を無視するための正規化。 */
    private function normalize(string $text): string
    {
        return str_replace([' ', '　'], '', mb_strtolower(mb_convert_kana($text, 'asKV')));
    }
}
