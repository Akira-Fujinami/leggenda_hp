<?php

namespace App\Services\BranchSurveyItems;

use App\Services\Report\CandidateSurveyCatalog;

/**
 * 依頼CT: AIの出力を、その枝の入力の本文と突き合わせる。found=trueの項目は、evidenceが入力の本文に
 * そのまま含まれるとき(空白・全角半角の違いだけ無視)だけ残し、含まれなければfalseとして扱う。
 * 本文に含まれるかだけを見る。確認を緩めて結果を良く見せないこと。
 */
class BranchSurveyItemsVerifier
{
    /**
     * @param  array<string, mixed>  $decoded  AIの出力
     * @return array{items: list<array{key: string, evidence: string}>, discarded: int}  itemsは調査の並び(割合の高い順)
     */
    public function verify(array $decoded, string $inputText): array
    {
        $known = array_keys((array) config('brand_wheel_candidate_survey.options', []));
        $haystack = $this->normalize($inputText);

        $found = [];
        $discarded = 0;
        foreach ((array) ($decoded['items'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = $row['key'] ?? null;
            if (! is_string($key) || ! in_array($key, $known, true) || isset($found[$key]) || ($row['found'] ?? false) !== true) {
                continue;
            }

            $evidence = is_string($row['evidence'] ?? null) ? trim($row['evidence']) : '';
            $needle = $this->normalize($evidence);
            if ($needle === '' || ! str_contains($haystack, $needle)) {
                $discarded++;

                continue;
            }

            $found[$key] = ['key' => $key, 'evidence' => $evidence];
        }

        $ordered = [];
        foreach ((new CandidateSurveyCatalog)->options() as $option) {
            if (isset($found[$option['key']])) {
                $ordered[] = $found[$option['key']];
            }
        }

        return ['items' => $ordered, 'discarded' => $discarded];
    }

    /** 空白をすべて除き、全角半角の違いをそろえる。 */
    public function normalize(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', mb_convert_kana($text, 'asKV'));
    }
}
