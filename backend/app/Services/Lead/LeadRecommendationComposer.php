<?php

namespace App\Services\Lead;

use App\Models\Recommendation;
use App\Support\Lead\LeadRecommendationCatalog;
use Illuminate\Support\Collection;

/**
 * リード(採用担当)向け「特に改善効果が見込まれる項目」の組み立て。
 * JSON API(LeadAnalysisController)とWord/PDFレポート(ReportViewModelBuilder)の
 * 両方から呼ぶ、唯一の定義元(2026-08-03)。
 *
 * 設計上の要点(いずれもユーザー指摘への対応):
 * 1. LeadRecommendationCatalogによる絞り込みは、sort_score順に上位N件を
 *    取るより先に行う。逆順にすると、上位N件がすべて対象外だった場合に
 *    0件になってしまう(対象外の提案を件数埋めのために混ぜることはしない)。
 * 2. 表示速度系(FCP/LCP/CLS/TBT)は同一グループへ集約し、グループ内で
 *    最もsort_scoreが高い1件だけを代表として残す(重複排除)。
 * 3. 画面とレポートは必ずこのクラスを共有する ―― 個別に実装すると、
 *    画面だけ直してレポートに技術用語が残る、という食い違いを作りかねない。
 *
 * @param  Collection<int, Recommendation>  $recommendations  metricResult.metricDefinition
 *         をeager load済みであること(呼び出し元の責務)。
 */
class LeadRecommendationComposer
{
    public function __construct(
        private readonly LeadRecommendationCatalog $catalog,
    ) {}

    /**
     * @param  Collection<int, Recommendation>  $recommendations
     * @return list<array{recommendation: Recommendation, title: string, description: string}>
     */
    public function compose(Collection $recommendations, int $limit): array
    {
        /** @var array<string, Recommendation> $bestByGroup */
        $bestByGroup = [];

        foreach ($recommendations as $recommendation) {
            $metricKey = $recommendation->metricResult?->metricDefinition?->key;

            if ($metricKey === null) {
                continue;
            }

            $groupKey = $this->catalog->groupKeyFor($metricKey);

            if ($groupKey === null) {
                continue;
            }

            $current = $bestByGroup[$groupKey] ?? null;

            if ($current === null || (float) $recommendation->sort_score > (float) $current->sort_score) {
                $bestByGroup[$groupKey] = $recommendation;
            }
        }

        return collect($bestByGroup)
            ->sortByDesc(fn (Recommendation $r) => (float) $r->sort_score)
            ->take($limit)
            ->map(function (Recommendation $recommendation) {
                $groupKey = $this->catalog->groupKeyFor($recommendation->metricResult->metricDefinition->key);

                return [
                    'recommendation' => $recommendation,
                    'title' => $this->catalog->title($groupKey),
                    'description' => $this->catalog->description($groupKey),
                ];
            })
            ->values()
            ->all();
    }
}
