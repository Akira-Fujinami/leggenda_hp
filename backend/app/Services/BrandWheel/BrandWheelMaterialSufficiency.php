<?php

namespace App\Services\BrandWheel;

/**
 * 依頼CH-1b(2026-10-01)、依頼CH追補-1(2026-10-01)で参照列を訂正。
 * BrandWheelComparisonSufficiency(matched件数の閾値判定)と同じ最小構造
 * ―― ただしこちらはAIに渡した材料のうち「起点由来」の量
 * (brand_wheel_analysis_results.input_origin_chars ―― 起点ページ本文＋
 * 起点URL配下のクロール段落、改行を含まない)を見る。判定が成立した
 * (status=success)かどうかとは独立した軸であり、status=successでも材料が
 * 薄ければfalseを返しうる。
 *
 * 【依頼CH追補-1による訂正】当初input_char_count(起点ページ本文＋クロール
 * 全件＋両者をつなぐ改行を含む値)を参照していたが、本番実測
 * (analysis_id=148)で確かめたところ、これを作るきっかけになったしんきん
 * 中央金庫の事例(起点URL配下のクロール文字数0、起点ページ本文のみは
 * 別に存在する)を閾値未満として捉えられない(起点ページ本文の分だけで
 * 閾値を超えてしまう)ことが判明したため、input_origin_charsに切り替えた。
 *
 * null(input_origin_chars未記録 ―― 旧データ)は「材料不足」と断定せず、
 * 十分とみなす(trueを返す) ―― 判定材料が無いことを「薄い」という断定に
 * 変換しない。
 */
class BrandWheelMaterialSufficiency
{
    public function isSufficient(?int $inputCharCount): bool
    {
        if ($inputCharCount === null) {
            return true;
        }

        return $inputCharCount >= (int) config('brand_wheel.insufficient_material_display_min_chars', 3000);
    }
}
