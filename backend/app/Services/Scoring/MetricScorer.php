<?php

namespace App\Services\Scoring;

use App\Enums\MetricResultStatus;
use App\Enums\NotFoundPolicy;
use App\Enums\ScoringType;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Services\Scoring\Strategies\BooleanMetricScorer;
use App\Services\Scoring\Strategies\InverseLinearMetricScorer;
use App\Services\Scoring\Strategies\LighthouseMetricScorer;
use App\Services\Scoring\Strategies\LinearMetricScorer;
use App\Services\Scoring\Strategies\ManualMetricScorer;
use App\Services\Scoring\Strategies\MetricScoringStrategy;
use App\Services\Scoring\Strategies\NotScoredMetricScorer;
use App\Services\Scoring\Strategies\RangeMetricScorer;
use App\Services\Scoring\Strategies\RatioMetricScorer;
use App\Services\Scoring\Strategies\ThresholdMetricScorer;
use App\Support\Scoring\MetricScoreOutcome;
use Throwable;

/**
 * MetricDefinition 1件 + MetricResult 1件から採点結果を決定する。
 *
 * 「分母に含めるか」の判定はscoring_typeやMetricDefinitionの設定に関わらず
 * 必ずこのクラスで一元的に行い、値の採点だけをStrategyへ委譲する
 * ―― Strategy実装がどんなに壊れていても(例外を投げても)500エラーには
 * ならず、0点フォールバックとして扱われる。
 */
class MetricScorer
{
    /** @var array<string, MetricScoringStrategy> */
    private array $strategies;

    public function __construct()
    {
        $this->strategies = [
            ScoringType::Boolean->value => new BooleanMetricScorer,
            ScoringType::Linear->value => new LinearMetricScorer,
            ScoringType::InverseLinear->value => new InverseLinearMetricScorer,
            ScoringType::Range->value => new RangeMetricScorer,
            ScoringType::Threshold->value => new ThresholdMetricScorer,
            ScoringType::Ratio->value => new RatioMetricScorer,
            ScoringType::Lighthouse->value => new LighthouseMetricScorer,
            ScoringType::Manual->value => new ManualMetricScorer,
            ScoringType::NotScored->value => new NotScoredMetricScorer,
        ];
    }

    public function score(MetricDefinition $definition, MetricResult $result): MetricScoreOutcome
    {
        if (! $definition->is_active) {
            return MetricScoreOutcome::excluded();
        }

        $scoringType = $this->resolveScoringType($definition);

        if ($scoringType === ScoringType::NotScored) {
            return MetricScoreOutcome::excluded();
        }

        return match ($result->status) {
            MetricResultStatus::Unavailable, MetricResultStatus::NotApplicable, MetricResultStatus::Error => MetricScoreOutcome::excluded(),
            MetricResultStatus::NotFound => $this->handleNotFound($definition, $result),
            MetricResultStatus::Success => $this->handleSuccess($definition, $result, $scoringType),
        };
    }

    private function handleNotFound(MetricDefinition $definition, MetricResult $result): MetricScoreOutcome
    {
        $policy = $this->resolveNotFoundPolicy($definition);
        $maxScore = (float) $definition->max_score;

        return match ($policy) {
            NotFoundPolicy::Zero => MetricScoreOutcome::scored(0.0, $maxScore),
            NotFoundPolicy::Exclude => MetricScoreOutcome::excluded(),
            NotFoundPolicy::Partial => MetricScoreOutcome::scored(
                max(0.0, min(1.0, (float) ($definition->not_found_partial_rate ?? 0.0))) * $maxScore,
                $maxScore,
            ),
        };
    }

    private function handleSuccess(MetricDefinition $definition, MetricResult $result, ScoringType $scoringType): MetricScoreOutcome
    {
        $maxScore = (float) $definition->max_score;
        $ratio = $this->safeRatio($scoringType, $definition, $result);

        return MetricScoreOutcome::scored($ratio * $maxScore, $maxScore);
    }

    private function safeRatio(ScoringType $scoringType, MetricDefinition $definition, MetricResult $result): float
    {
        $strategy = $this->strategies[$scoringType->value] ?? $this->strategies[ScoringType::NotScored->value];

        try {
            $ratio = $strategy->calculateRatio($definition, $result);
        } catch (Throwable $e) {
            report($e);

            return 0.0;
        }

        if (! is_finite($ratio)) {
            return 0.0;
        }

        return max(0.0, min(1.0, $ratio));
    }

    /**
     * scoring_type/not_found_policyはモデル側であえてenumキャストしていない
     * (Laravelのenumキャストは値がenumに一致しないとアクセス時に例外を
     * 投げるため、DB内の不正な文字列がそのまま500エラーに直結してしまう)。
     * ここでtryFrom()を使い、不正な文字列は安全側の既定値へフォールバックする。
     */
    private function resolveScoringType(MetricDefinition $definition): ScoringType
    {
        return ScoringType::tryFrom((string) $definition->scoring_type) ?? ScoringType::NotScored;
    }

    private function resolveNotFoundPolicy(MetricDefinition $definition): NotFoundPolicy
    {
        return NotFoundPolicy::tryFrom((string) $definition->not_found_policy) ?? NotFoundPolicy::Zero;
    }
}
