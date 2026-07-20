<?php

namespace App\Services\Recommendation;

use App\Enums\RecommendationEffort;
use App\Enums\RecommendationImpact;
use App\Enums\RecommendationPriority;
use App\Enums\RecommendationSource;
use App\Enums\RecommendationStatus;
use App\Models\CategoryDefinition;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Models\Recommendation;
use App\Models\WebsiteAnalysis;
use App\Services\Scoring\MetricScorer;
use Illuminate\Support\Collection;

/**
 * MetricDefinition.recommendation_templateとMetricResultから、ルールベースで
 * 改善提案を生成する。同一Analysisで再実行しても、
 * (website_analysis_id, metric_result_id)の一意制約により重複作成されない
 * (updateOrCreateで冪等)。
 *
 * 効果が無くなった(満点になった)提案は自動ではdismissedにしない
 * (履歴比較でrecommendation_resolvedを判定する材料として、過去に指摘した
 * 事実は残す設計。現在の状態はstatus/再生成時のcurrent_value更新で追える)。
 */
class RecommendationGenerator
{
    /**
     * 工数(effort)はカテゴリ単位の粗い既定値。個々のMetricDefinitionで
     * 上書きしたい場合は将来的にeffort専用カラムを追加できる。
     */
    private const EFFORT_BY_CATEGORY = [
        'technical_seo' => RecommendationEffort::Small,
        'content' => RecommendationEffort::Medium,
        'performance' => RecommendationEffort::Large,
        'accessibility' => RecommendationEffort::Medium,
        'technology' => RecommendationEffort::Small,
        'conversion' => RecommendationEffort::Medium,
        'authority' => RecommendationEffort::Large,
    ];

    /**
     * キー(抑制対象) => キー(これがtrueなら抑制対象の提案を生成しない)。
     * 例: tel/mailtoリンクが無くても、問い合わせ導線(contact_cta_present)が
     * 別途存在するなら、電話番号の欠如だけを理由に緊急対応を促さない
     * (旅行予約サイト等ではWeb問い合わせ・チャット中心のこともあるため)。
     * 採点(スコア)自体は変えず、あくまで「改善提案を出すかどうか」のみを
     * 調整する ―― 採点まで甘くすると実際に電話導線が無い事実が見えなくなるため。
     *
     * @var array<string, string>
     */
    private const SUPPRESSED_WHEN_SATISFIED = [
        'tel_or_mailto_present' => 'contact_cta_present',
    ];

    public function __construct(
        private readonly MetricScorer $scorer,
        private readonly RecommendationPriorityCalculator $priorityCalculator,
    ) {}

    /**
     * @param  Collection<int, MetricResult>  $results  metricDefinition読み込み済み
     * @param  Collection<int, CategoryDefinition>  $categories
     */
    public function generate(WebsiteAnalysis $websiteAnalysis, Collection $results, Collection $categories): void
    {
        foreach ($results as $result) {
            $definition = $result->metricDefinition;

            if ($definition === null || ! $definition->is_active || $definition->recommendation_template === null) {
                continue;
            }

            $outcome = $this->scorer->score($definition, $result);

            if (! $outcome->countsTowardScore || $outcome->maxScore === null || $outcome->maxScore <= 0) {
                continue;
            }

            $ratio = $outcome->score / $outcome->maxScore;

            if ($ratio >= 0.999) {
                // 満点相当のためこの回では提案しない(既存の提案があればstatus等はそのまま残す)。
                continue;
            }

            if ($this->isSuppressedBySatisfiedAlternative($definition->key, $results)) {
                continue;
            }

            $category = $categories->firstWhere('key', $definition->category_key);
            $categoryWeight = $category !== null ? (float) $category->weight : 0.0;

            $impact = $this->classifyImpact($definition, $categoryWeight);
            $effort = self::EFFORT_BY_CATEGORY[$definition->category_key] ?? RecommendationEffort::Medium;
            $priority = $this->classifyPriority($impact, $ratio);
            $confidence = $result->confidence !== null ? (float) $result->confidence : 1.0;

            $sortScore = $this->priorityCalculator->calculate(
                impact: $impact,
                effort: $effort,
                categoryWeight: $categoryWeight,
                metricWeight: (float) $definition->weight,
                competitorGap: 0.0,
                confidence: $confidence,
            );

            Recommendation::query()->updateOrCreate(
                ['website_analysis_id' => $websiteAnalysis->id, 'metric_result_id' => $result->id],
                [
                    'category_key' => $definition->category_key,
                    'title' => $definition->name,
                    'description' => $definition->recommendation_template,
                    'evidence' => $result->evidence,
                    'current_value' => $result->normalized_value,
                    'recommended_value' => $this->recommendedValue($definition),
                    'priority' => $priority,
                    'impact' => $impact,
                    'effort' => $effort,
                    'confidence' => $confidence,
                    'status' => RecommendationStatus::Open,
                    'source' => RecommendationSource::Rule,
                    'sort_score' => $sortScore,
                ],
            );
        }
    }

    /**
     * @param  Collection<int, MetricResult>  $results
     */
    private function isSuppressedBySatisfiedAlternative(string $key, Collection $results): bool
    {
        $satisfyingKey = self::SUPPRESSED_WHEN_SATISFIED[$key] ?? null;

        if ($satisfyingKey === null) {
            return false;
        }

        $satisfyingResult = $results->first(fn (MetricResult $r) => $r->metricDefinition?->key === $satisfyingKey);

        return (bool) ($satisfyingResult?->normalized_value['value'] ?? false);
    }

    private function classifyImpact(MetricDefinition $definition, float $categoryWeight): RecommendationImpact
    {
        $maxScore = (float) $definition->max_score;

        if ($maxScore >= 3 || $categoryWeight >= 20) {
            return RecommendationImpact::High;
        }

        if ($maxScore >= 1.5) {
            return RecommendationImpact::Medium;
        }

        return RecommendationImpact::Low;
    }

    private function classifyPriority(RecommendationImpact $impact, float $ratio): RecommendationPriority
    {
        if ($impact === RecommendationImpact::High) {
            return $ratio <= 0.01 ? RecommendationPriority::Critical : RecommendationPriority::High;
        }

        if ($impact === RecommendationImpact::Medium) {
            return RecommendationPriority::Medium;
        }

        return RecommendationPriority::Low;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recommendedValue(MetricDefinition $definition): ?array
    {
        if ($definition->target_value !== null) {
            return ['target_value' => (float) $definition->target_value];
        }

        if ($definition->minimum_value !== null || $definition->maximum_value !== null) {
            return [
                'minimum_value' => $definition->minimum_value !== null ? (float) $definition->minimum_value : null,
                'maximum_value' => $definition->maximum_value !== null ? (float) $definition->maximum_value : null,
            ];
        }

        return null;
    }
}
