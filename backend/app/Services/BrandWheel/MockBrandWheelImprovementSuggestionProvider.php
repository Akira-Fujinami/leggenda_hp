<?php

namespace App\Services\BrandWheel;

use App\Services\BrandWheel\Data\BrandWheelImprovementSuggestionInput;
use App\Services\BrandWheel\Data\BrandWheelImprovementSuggestionOutcome;
use App\Services\BrandWheel\Data\BrandWheelImprovementSuggestionResult;

/**
 * 開発・テスト向けの改善提案Provider。外部APIを一切呼び出さず、常に同じ
 * 入力に対して同じ出力を返す(決定的)。MockBrandWheelAnalysisProviderと
 * 同じ方針で、モックはあくまでモックだと明示する(実際のAI提言を捏造しない)。
 */
class MockBrandWheelImprovementSuggestionProvider implements BrandWheelImprovementSuggestionProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function model(): ?string
    {
        return null;
    }

    public function promptVersion(): ?string
    {
        return null;
    }

    public function analyze(BrandWheelImprovementSuggestionInput $input): BrandWheelImprovementSuggestionOutcome
    {
        $result = new BrandWheelImprovementSuggestionResult(
            onePoint: '[デモデータ] モックプロバイダのためワンポイントはありません。',
            recommendation: '[デモデータ] モックプロバイダのため改善提案はありません。',
            focusSubElementKeys: [],
            reason: '[デモデータ] モックプロバイダのため理由はありません。',
            recommendedContents: [],
            midTermAction: null,
            quickWin: null,
            implementationDifficulty: null,
            candidateImpact: null,
            gapClosing: [],
            differentiationOpportunities: [],
            provider: 'mock',
            model: null,
            isMock: true,
            promptVersion: null,
        );

        return new BrandWheelImprovementSuggestionOutcome($result, usageInputTokens: null, usageOutputTokens: null);
    }
}
