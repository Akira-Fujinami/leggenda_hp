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

    /**
     * 完全抑制ではないが、ライブ対応に近い代替導線(チャット・ヘルプ)が
     * ある場合に提案自体は生成しつつpriorityをLowへ強制するテーブル。
     * FAQだけの場合はこちらには含めない(SOFTENED_WHEN_SATISFIEDへ)
     * ―― FAQのみで緊急度を下げてしまうと、実際に電話・問い合わせ導線が
     * 無い事実が見えなくなるため。
     *
     * @var array<string, list<string>>
     */
    private const DOWNGRADED_WHEN_SATISFIED = [
        'tel_or_mailto_present' => ['chatbot_detected', 'help_center_link_present'],
        'contact_cta_present' => ['chatbot_detected', 'help_center_link_present'],
        'form_present' => ['chatbot_detected', 'help_center_link_present'],
    ];

    /**
     * priorityは変えず、説明文にのみ代替導線への言及を追記して断定を
     * 弱めるテーブル(FAQのみが存在する場合)。
     *
     * @var array<string, list<string>>
     */
    private const SOFTENED_WHEN_SATISFIED = [
        'tel_or_mailto_present' => ['faq_link_present'],
        'contact_cta_present' => ['faq_link_present'],
        'form_present' => ['faq_link_present'],
    ];

    private const ALTERNATIVE_CHANNEL_LABELS = [
        'chatbot_detected' => 'チャットサポート',
        'help_center_link_present' => 'ヘルプ・サポートページ',
        'faq_link_present' => 'FAQ',
    ];

    /**
     * ratio(scoring_type=ratio)の優先度をmetricキーごとに個別設定する
     * ためのテーブル。scoring_type全体に一律適用しない(将来別のratio指標
     * (例: 他の充足率系メトリクス)に、alt用に調整した閾値が意図せず流用
     * されるのを防ぐため、キーを明示的に列挙する設計とする)。
     * 該当キーが無い場合は既存のclassifyPriority(impact基準)にフォール
     * バックする。
     *
     * @var array<string, array{low: float, medium: float, high: float}>
     */
    private const RATIO_PRIORITY_THRESHOLDS = [
        'img_alt_coverage' => ['low' => 0.95, 'medium' => 0.80, 'high' => 0.50],
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
            $confidence = $result->confidence !== null ? (float) $result->confidence : 1.0;
            $priority = isset(self::RATIO_PRIORITY_THRESHOLDS[$definition->key])
                ? $this->classifyRatioAwarePriority($ratio, self::RATIO_PRIORITY_THRESHOLDS[$definition->key])
                : $this->classifyPriority($impact, $ratio, $confidence);
            $description = $this->resolveDescription($definition, $result);

            $downgradedBy = $this->satisfiedAlternatives($definition->key, $results, self::DOWNGRADED_WHEN_SATISFIED);
            if ($downgradedBy !== []) {
                $priority = RecommendationPriority::Low;
                $description .= $this->downgradeSuffix($downgradedBy);
            } else {
                $softenedBy = $this->satisfiedAlternatives($definition->key, $results, self::SOFTENED_WHEN_SATISFIED);
                if ($softenedBy !== []) {
                    $description .= $this->softenSuffix($softenedBy);
                }
            }

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
                    'description' => $description,
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

    /**
     * $table内で$keyに対応する候補キーのうち、実際にtrueとして記録されて
     * いるものの一覧を返す(OR条件。1つでも満たされていれば該当)。
     *
     * @param  array<string, list<string>>  $table
     * @param  Collection<int, MetricResult>  $results
     * @return list<string>
     */
    private function satisfiedAlternatives(string $key, Collection $results, array $table): array
    {
        $satisfied = [];

        foreach ($table[$key] ?? [] as $candidateKey) {
            $candidateResult = $results->first(fn (MetricResult $r) => $r->metricDefinition?->key === $candidateKey);

            if ((bool) ($candidateResult?->normalized_value['value'] ?? false)) {
                $satisfied[] = $candidateKey;
            }
        }

        return $satisfied;
    }

    /**
     * @param  list<string>  $keys
     */
    private function downgradeSuffix(array $keys): string
    {
        return '(なお、'.$this->joinAlternativeLabels($keys).'が確認できるため、緊急度は低めです。)';
    }

    /**
     * @param  list<string>  $keys
     */
    private function softenSuffix(array $keys): string
    {
        return '(なお、'.$this->joinAlternativeLabels($keys).'が確認できます。分かりやすいか合わせてご確認ください。)';
    }

    /**
     * @param  list<string>  $keys
     */
    private function joinAlternativeLabels(array $keys): string
    {
        return implode('・', array_map(fn (string $key) => self::ALTERNATIVE_CHANNEL_LABELS[$key] ?? $key, $keys));
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

    /**
     * confidenceが低い(例: Lighthouse単発計測由来で0.75など)場合、
     * ratio<=0.01だけでCriticalへ引き上げない ―― 単発計測の極端な値を
     * 無条件に「確定的な緊急事態」と断定しないための、Lighthouse専用では
     * ない汎用のconfidenceベースの安全弁。
     */
    private function classifyPriority(RecommendationImpact $impact, float $ratio, float $confidence = 1.0): RecommendationPriority
    {
        if ($impact === RecommendationImpact::High) {
            return ($ratio <= 0.01 && $confidence >= 0.85) ? RecommendationPriority::Critical : RecommendationPriority::High;
        }

        if ($impact === RecommendationImpact::Medium) {
            return RecommendationPriority::Medium;
        }

        return RecommendationPriority::Low;
    }

    /**
     * RATIO_PRIORITY_THRESHOLDSに登録されたmetricキー専用の優先度判定。
     * 他のscoring_type=ratio指標には影響しない。
     *
     * @param  array{low: float, medium: float, high: float}  $thresholds
     */
    private function classifyRatioAwarePriority(float $ratio, array $thresholds): RecommendationPriority
    {
        if ($ratio >= $thresholds['low']) {
            return RecommendationPriority::Low;
        }

        if ($ratio >= $thresholds['medium']) {
            return RecommendationPriority::Medium;
        }

        if ($ratio >= $thresholds['high']) {
            return RecommendationPriority::High;
        }

        return RecommendationPriority::Critical;
    }

    /**
     * metricキー別に、MetricDefinition.recommendation_templateだけでは
     * 表現できない状態別の文言を返す。h1_singleはvalid_count===0(H1なし)と
     * valid_count>=2(主要H1が複数)を同じデフォルト文言で扱うと紛らわしいため、
     * ここでのみ複数検出時の文言を上書きする。該当しないmetricは
     * デフォルトのrecommendation_templateをそのまま返す。
     */
    private function resolveDescription(MetricDefinition $definition, MetricResult $result): string
    {
        if ($definition->key === 'h1_single') {
            $validCount = (int) ($result->raw_value['valid_count'] ?? 0);

            if ($validCount >= 2) {
                return '主要なH1が複数検出されました。ページの主見出し構造を確認してください。';
            }
        }

        return $definition->recommendation_template;
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
