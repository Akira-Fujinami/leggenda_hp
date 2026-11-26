<?php

namespace App\Services\BrandWheel;

use App\Enums\AnalysisKind;
use App\Models\BrandWheelAnalysisResult;
use App\Models\WebsiteAnalysis;

/**
 * 依頼DU(2026-11-26): 無料診断(自社1×競合1)を起点にした3〜5社比較で、自社サイトの判定結果を起点の無料診断と同じにする。
 *
 * 同じサイトでも、AIをもう一度呼ぶと24項目の判定が数点ずれる(巡回で集まるページも変わりうる)。お客様に渡した無料診断と、
 * そのあとの比較で自社の数値が違うのは困るため、比較の自社サイトは、起点の無料診断の判定結果(成功したもの)を写して使う。
 * 起点の無料診断の結果は変えない。競合サイトは従来どおり判定する。
 *
 * 写すのは、次のすべてを満たすときだけ(満たさなければ null を返し、従来どおりAIで判定する):
 *  - config('brand_wheel.comparison_reuse_source_self_result_enabled') が true
 *  - 判定するサイトが、比較(kind=admin_comparison・起点あり)の自社サイト
 *  - 起点の無料診断の自社サイトと、正規化したURLが同じ(比較を作るときに自社URLを変えた場合は写さない)
 *  - 起点の無料診断の自社サイトに、成功した判定結果がある
 */
class ComparisonSelfResultReuser
{
    public function sourceResultFor(WebsiteAnalysis $websiteAnalysis): ?BrandWheelAnalysisResult
    {
        if (! (bool) config('brand_wheel.comparison_reuse_source_self_result_enabled', true)) {
            return null;
        }

        $websiteAnalysis->loadMissing(['analysis.sourceAnalysis.websiteAnalyses.website', 'website']);
        $analysis = $websiteAnalysis->analysis;
        if ($analysis === null || $analysis->kind !== AnalysisKind::AdminComparison || $analysis->sourceAnalysis === null) {
            return null;
        }
        if (! (bool) $websiteAnalysis->website?->is_primary) {
            return null;
        }

        $sourceSelf = $analysis->sourceAnalysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => (bool) $wa->website?->is_primary);
        if ($sourceSelf === null || ! $this->sameUrl($sourceSelf, $websiteAnalysis)) {
            return null;
        }

        return BrandWheelAnalysisResult::query()
            ->where('website_analysis_id', $sourceSelf->id)
            ->where('status', 'success')
            ->latest('generated_at')
            ->latest('id')
            ->first();
    }

    /**
     * 写す項目(判定の結果と、その判定に使った材料の記録)。
     *
     * @return array<string, mixed>
     */
    public static function copiedAttributes(BrandWheelAnalysisResult $source): array
    {
        return [
            'provider' => $source->provider,
            'model' => $source->model,
            'status' => 'success',
            'prompt_version' => $source->prompt_version,
            'axes' => $source->axes,
            'core_value_readable' => $source->core_value_readable,
            'core_value_evidence' => $source->core_value_evidence,
            'key_message' => $source->key_message,
            'impression' => $source->impression,
            'positive_impression' => $source->positive_impression,
            'negative_impression' => $source->negative_impression,
            'quality_dimension_notes' => $source->quality_dimension_notes,
            'cautions' => $source->cautions,
            'axis_state_counts' => $source->axis_state_counts,
            'is_mock' => $source->is_mock,
            'input_hash' => $source->input_hash,
            'input_truncated' => $source->input_truncated,
            'input_char_count' => $source->input_char_count,
            'input_origin_chars' => $source->input_origin_chars,
            'input_adopted_paragraph_count' => $source->input_adopted_paragraph_count,
            'source_pages' => $source->source_pages,
        ];
    }

    private function sameUrl(WebsiteAnalysis $a, WebsiteAnalysis $b): bool
    {
        $left = $this->comparable((string) ($a->website?->normalized_url ?: $a->website?->url));
        $right = $this->comparable((string) ($b->website?->normalized_url ?: $b->website?->url));

        return $left !== '' && $left === $right;
    }

    /** 大文字小文字と末尾の「/」の違いは同じとみなす。 */
    private function comparable(string $url): string
    {
        return rtrim(mb_strtolower(trim($url)), '/');
    }
}
