<?php

namespace App\Services\AiAnalysis;

use App\Services\AiAnalysis\Data\AiAnalysisInput;
use App\Services\AiAnalysis\Data\AiAnalysisResult;

/**
 * AI分析Providerの抽象化。Semrush等のSeoDataProviderと同様に、実際のAI API
 * (OpenAI/Anthropic等)固有の呼び出し方法・レスポンス形式をJob/Controllerから
 * 隠蔽する。次PhaseでMockAiAnalysisProvider以外の実装を追加する際も、
 * この契約(AiAnalysisInputを受け取りAiAnalysisResultを返す)だけを満たせばよい。
 */
interface AiAnalysisProvider
{
    public function analyze(AiAnalysisInput $input): AiAnalysisResult;

    public function name(): string;
}
