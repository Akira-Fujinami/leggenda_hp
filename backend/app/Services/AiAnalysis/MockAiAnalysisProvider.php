<?php

namespace App\Services\AiAnalysis;

use App\Services\AiAnalysis\Data\AiAnalysisInput;
use App\Services\AiAnalysis\Data\AiAnalysisResult;

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

    public function analyze(AiAnalysisInput $input): AiAnalysisResult
    {
        $siteLabel = $input->websiteName ?? 'このサイト';

        $summary = sprintf(
            '[デモデータ] %sの総合スコアは%.1f点、測定カバー率は%.0f%%です。'.
            'これはモックプロバイダによる仮の文章であり、実際のAIによる分析結果ではありません。',
            $siteLabel,
            $input->overallScore,
            $input->coverageRate,
        );

        $priorityActions = $input->recommendations
            ->take(self::MAX_PRIORITY_ACTIONS)
            ->map(fn ($recommendation) => $recommendation->title)
            ->values()
            ->all();

        $competitorInsights = $input->competitorGaps
            ->map(fn ($gap) => sprintf(
                '%sとの総合スコア差: %+.1f点',
                $gap->competitorName,
                $gap->scoreGap,
            ))
            ->values()
            ->all();

        return new AiAnalysisResult(
            summary: $summary,
            strengths: array_slice($input->strengths, 0, self::MAX_STRENGTHS),
            weaknesses: array_slice($input->weaknesses, 0, self::MAX_WEAKNESSES),
            priorityActions: $priorityActions,
            competitorInsights: $competitorInsights,
            cautions: ['これはモックデータです。実際のAI分析結果ではありません。'],
            confidence: 0.0,
            provider: 'mock',
            model: null,
            isMock: true,
        );
    }
}
