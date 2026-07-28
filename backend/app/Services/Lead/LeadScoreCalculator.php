<?php

namespace App\Services\Lead;

use App\Enums\MetricResultStatus;
use App\Enums\ScoringType;
use App\Models\MetricDefinition;
use App\Models\MetricResult;
use App\Services\Scoring\ConfidenceCalculator;
use App\Services\Scoring\CoverageCalculator;
use App\Services\Scoring\MetricScorer;
use App\Support\Lead\LeadMetricCatalog;
use App\Support\Scoring\CategoryScoreResult;
use App\Support\Scoring\WebsiteScoreResult;
use Illuminate\Support\Collection;

/**
 * リード(採用担当)向けの点数を、社内版の7カテゴリ100点(OverallScoreCalculator)
 * とは完全に別建てで算出する。
 *
 * 背景(2026-07-28のユーザー指摘): 社内版のconfigured_max_scoreはカテゴリの
 * weight合計であり、LeadMetricCatalog(4観点)に表示していない約35項目
 * (HTTPS・canonical・構造化データ・外部SEO等)も含めて算出される。その結果、
 * ①Semrush未設定時は外部SEO15点分が構造的に常に未取得となり総合点が
 * 最大85点にしか届かない、②4観点がほぼ「良好」でも画面に出ない項目の
 * 増減で総合点が動く、という2つの不整合が生まれていた。
 *
 * この計算機はLeadMetricCatalogに登録されている指標(4観点いずれかに
 * マッピングされている項目)だけを対象に、社内版と同じ計算式
 * (score/max_score、カバー率=available/configured、確信度)を
 * 再算出することで、リードに見せる数値と4観点の表示内容を完全一致させる。
 *
 * 計算式そのもの(MetricScorer/CoverageCalculator/ConfidenceCalculator)は
 * 社内版と共有・再利用するのみで、採点ロジックの再実装は一切行わない。
 * OverallScoreCalculator・CategoryDefinition.weight・社内版
 * configured_max_scoreの意味は変更しない ―― ここで算出する点数は
 * 社内版のスコアとは別建ての値であり、商談時に取り違えないこと
 * (該当ページ・レポートには必ずその旨を明示する)。
 */
class LeadScoreCalculator
{
    public function __construct(
        private readonly MetricScorer $scorer,
        private readonly LeadMetricCatalog $catalog,
        private readonly CoverageCalculator $coverage,
        private readonly ConfidenceCalculator $confidence,
    ) {}

    /**
     * @param  Collection<int, MetricDefinition>  $activeDefinitions  is_active=trueの全指標定義
     * @param  Collection<int, MetricResult>  $results  対象WebsiteAnalysisのMetricResult
     */
    public function calculate(Collection $activeDefinitions, Collection $results): WebsiteScoreResult
    {
        $catalogDefinitions = $activeDefinitions->filter(
            fn (MetricDefinition $definition) => $this->catalog->isDisplayed($definition->key)
        );

        $summary = [
            'success' => 0, 'not_found' => 0, 'unavailable' => 0, 'error' => 0, 'not_applicable' => 0,
            'scored_unavailable' => 0, 'informational_unavailable' => 0,
        ];

        $resultsByDefinitionId = $results->keyBy('metric_definition_id');
        $outcomesByResultId = [];
        $scoreByPerspective = [];
        $availableByPerspective = [];
        $configuredMaxByPerspective = [];

        foreach ($catalogDefinitions as $definition) {
            $perspective = $this->catalog->perspectiveFor($definition->key);
            $isInformational = ScoringType::tryFrom((string) $definition->scoring_type) === ScoringType::NotScored;

            // configured_max_score(分母)は「このリリースで何点満点なのか」という
            // 設計上固定の値。実測できたかどうかに関わらず、対象指標のmax_score
            // 合計として先に積み上げる(社内版のconfigured_max_scoreがカテゴリの
            // weight合計で、実測結果に左右されないのと同じ考え方)。
            if (! $isInformational) {
                $configuredMaxByPerspective[$perspective] = ($configuredMaxByPerspective[$perspective] ?? 0.0) + (float) $definition->max_score;
            }

            $result = $resultsByDefinitionId->get($definition->id);

            if ($result === null) {
                $summary[$isInformational ? 'informational_unavailable' : 'scored_unavailable']++;

                continue;
            }

            $outcome = $this->scorer->score($definition, $result);
            $outcomesByResultId[$result->id] = $outcome;

            $summary[match ($result->status) {
                MetricResultStatus::Success => 'success',
                MetricResultStatus::NotFound => 'not_found',
                MetricResultStatus::Unavailable => 'unavailable',
                MetricResultStatus::Error => 'error',
                MetricResultStatus::NotApplicable => 'not_applicable',
            }]++;

            if (in_array($result->status, [MetricResultStatus::Unavailable, MetricResultStatus::Error], true)) {
                $summary[$isInformational ? 'informational_unavailable' : 'scored_unavailable']++;
            }

            if ($outcome->countsTowardScore) {
                $scoreByPerspective[$perspective] = ($scoreByPerspective[$perspective] ?? 0.0) + $outcome->score;
                $availableByPerspective[$perspective] = ($availableByPerspective[$perspective] ?? 0.0) + $outcome->maxScore;
            }
        }

        $perspectiveScores = collect(array_keys(LeadMetricCatalog::PERSPECTIVE_LABELS))
            ->map(function (string $perspectiveKey) use ($scoreByPerspective, $availableByPerspective, $configuredMaxByPerspective) {
                $configuredMax = $configuredMaxByPerspective[$perspectiveKey] ?? 0.0;
                $available = $availableByPerspective[$perspectiveKey] ?? 0.0;
                $score = $scoreByPerspective[$perspectiveKey] ?? 0.0;

                return new CategoryScoreResult(
                    key: $perspectiveKey,
                    name: LeadMetricCatalog::PERSPECTIVE_LABELS[$perspectiveKey],
                    score: $score,
                    maxAvailableScore: $available,
                    configuredMaxScore: $configuredMax,
                    coverageRate: $this->coverage->rate($available, $configuredMax),
                );
            })
            ->values();

        $availableScore = (float) $perspectiveScores->sum('maxAvailableScore');
        $overallScore = (float) $perspectiveScores->sum('score');
        $configuredMaxScore = (float) $perspectiveScores->sum('configuredMaxScore');

        // 確信度(信頼度)は4観点に含まれる指標の結果だけを対象に再算出する
        // ―― 非表示項目のconfidenceが紛れ込まないよう、resultsを先に絞り込む。
        $catalogResults = $results->filter(
            fn (MetricResult $result) => $result->metricDefinition !== null && $this->catalog->isDisplayed($result->metricDefinition->key)
        );

        return new WebsiteScoreResult(
            overallScore: $overallScore,
            displayScore: (int) round($overallScore),
            availableScore: $availableScore,
            configuredMaxScore: $configuredMaxScore,
            coverageRate: $this->coverage->rate($availableScore, $configuredMaxScore),
            confidenceRate: $this->confidence->rate($catalogResults, $outcomesByResultId),
            categoryScores: $perspectiveScores,
            metricSummary: $summary,
        );
    }
}
