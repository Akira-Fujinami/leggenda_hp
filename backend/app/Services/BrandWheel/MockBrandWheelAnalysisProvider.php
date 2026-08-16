<?php

namespace App\Services\BrandWheel;

use App\Services\BrandWheel\Data\BrandWheelAnalysisInput;
use App\Services\BrandWheel\Data\BrandWheelAnalysisOutcome;
use App\Services\BrandWheel\Data\BrandWheelAnalysisResult;
use App\Services\BrandWheel\Data\BrandWheelAxisResult;
use App\Services\BrandWheel\Data\BrandWheelCoreValueResult;

/**
 * 開発・テスト向けのブランド・ホイール分析Provider。外部APIを一切呼び出さず、
 * 常に同じ入力に対して同じ出力を返す(決定的)。MockAiAnalysisProviderと
 * 同じ方針で、結果はすべて「読み取れなかった」側に倒す(実在しないevidenceを
 * 捏造して検証を通過させるようなことはしない ―― モックはあくまでモックだと
 * 明示するため、matched_sub_elementsは常に空、stateは常にunreadにする)。
 */
class MockBrandWheelAnalysisProvider implements BrandWheelAnalysisProvider
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

    public function analyze(BrandWheelAnalysisInput $input): BrandWheelAnalysisOutcome
    {
        $axisKeys = array_keys((array) config('brand_wheel.axes', []));

        $axes = array_map(fn (string $axisKey) => new BrandWheelAxisResult(
            axisKey: $axisKey,
            matchedSubElements: [],
            discardedSubElements: [],
            claimedSubElementCount: 0,
            state: 'unread',
        ), $axisKeys);

        $qualityDimensionKeys = array_keys((array) config('brand_wheel.quality_dimensions', []));
        $qualityDimensionNotes = array_fill_keys(
            $qualityDimensionKeys,
            '[デモデータ] モックプロバイダのため所見はありません。',
        );

        $result = new BrandWheelAnalysisResult(
            axes: $axes,
            coreValue: new BrandWheelCoreValueResult(readable: false, evidence: null),
            keyMessage: '[デモデータ] モックプロバイダのためキーメッセージはありません。',
            impression: ['[デモデータ] モックプロバイダのため印象の記述はありません。'],
            positiveImpression: '[デモデータ] モックプロバイダのためポジティブな印象の記述はありません。',
            negativeImpression: '[デモデータ] モックプロバイダのためネガティブな印象の記述はありません。',
            qualityDimensionNotes: $qualityDimensionNotes,
            cautions: ['これはモックデータです。実際のAI分析結果ではありません。'],
            axisStateCounts: ['read' => 0, 'partial' => 0, 'unread' => count($axisKeys)],
            provider: 'mock',
            model: null,
            isMock: true,
            promptVersion: null,
        );

        return new BrandWheelAnalysisOutcome($result, usageInputTokens: null, usageOutputTokens: null);
    }
}
