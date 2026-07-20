<?php

namespace App\Services\AiAnalysis\Data;

/**
 * AiAnalysisProvider::analyze()の戻り値。AiAnalysisResult本体に加えて、
 * 実際のAPI呼び出しでしか分からないトークン使用量を保持する
 * (GenerateAiAnalysisJobがai_analysis_resultsへ記録するため)。
 */
readonly class AiAnalysisOutcome
{
    public function __construct(
        public AiAnalysisResult $result,
        public ?int $usageInputTokens = null,
        public ?int $usageOutputTokens = null,
    ) {
    }
}
