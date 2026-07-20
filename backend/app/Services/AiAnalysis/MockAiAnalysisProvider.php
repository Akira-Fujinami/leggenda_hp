<?php

namespace App\Services\AiAnalysis;

use App\Services\AiAnalysis\Data\AiAnalysisInput;
use App\Services\AiAnalysis\Data\AiAnalysisOutcome;
use App\Services\AiAnalysis\Data\AiAnalysisResult;
use App\Services\AiAnalysis\Data\AiCompetitorInsightItem;
use App\Services\AiAnalysis\Data\AiPriorityActionItem;
use App\Services\AiAnalysis\Data\AiStrengthItem;
use App\Services\AiAnalysis\Data\AiWeaknessItem;

/**
 * 開発・テスト向けのAI分析Provider。外部APIを一切呼び出さず、既存の
 * AiAnalysisInputの値から決定論的に(=同じ入力なら常に同じ出力を)組み立てる。
 * confidenceは他のモックデータ(Semrushのmock等)と同様に常に0.0とし、
 * スコア対象・実データ扱いされないようにする。
 */
class MockAiAnalysisProvider implements AiAnalysisProvider
{
    private const int MAX_STRENGTHS = 3;

    private const int MAX_WEAKNESSES = 3;

    private const int MAX_PRIORITY_ACTIONS = 3;

    public function name(): string
    {
        return 'mock';
    }

    public function analyze(AiAnalysisInput $input): AiAnalysisOutcome
    {
        $siteLabel = $input->websiteName ?? 'このサイト';

        $summary = sprintf(
            '[デモデータ] %sの総合スコアは%.1f点、測定カバー率は%.0f%%です。'.
            'これはモックプロバイダによる仮の文章であり、実際のAIによる分析結果ではありません。',
            $siteLabel,
            $input->overallScore,
            $input->coverageRate,
        );

        $strengths = array_slice(
            array_map(fn (string $label) => new AiStrengthItem(title: $label, description: $label, evidenceMetricKeys: []), $input->strengths),
            0,
            self::MAX_STRENGTHS,
        );

        $weaknesses = array_slice(
            array_map(fn (string $label) => new AiWeaknessItem(title: $label, description: $label, evidenceMetricKeys: []), $input->weaknesses),
            0,
            self::MAX_WEAKNESSES,
        );

        $priorityActions = $input->recommendations
            ->take(self::MAX_PRIORITY_ACTIONS)
            ->map(fn ($recommendation) => new AiPriorityActionItem(
                title: $recommendation->title,
                description: $recommendation->title,
                priority: $recommendation->priority,
                impact: $recommendation->impact,
                effort: $recommendation->effort,
                evidenceMetricKeys: [],
            ))
            ->values()
            ->all();

        $competitorInsights = $input->competitorGaps
            ->map(fn ($gap) => new AiCompetitorInsightItem(
                title: sprintf('%sとのスコア差', $gap->competitorName),
                description: sprintf('%sとの総合スコア差: %+.1f点', $gap->competitorName, $gap->scoreGap),
                competitorWebsiteAnalysisIds: [$gap->websiteAnalysisId],
            ))
            ->values()
            ->all();

        $result = new AiAnalysisResult(
            summary: $summary,
            strengths: $strengths,
            weaknesses: $weaknesses,
            priorityActions: $priorityActions,
            competitorInsights: $competitorInsights,
            cautions: ['これはモックデータです。実際のAI分析結果ではありません。'],
            confidence: 0.0,
            provider: 'mock',
            model: null,
            isMock: true,
        );

        return new AiAnalysisOutcome($result, usageInputTokens: null, usageOutputTokens: null);
    }
}
