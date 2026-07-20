<?php

namespace App\Services\AiAnalysis;

use App\Services\AiAnalysis\Data\AiAnalysisInput;
use App\Services\AiAnalysis\Data\AiAnalysisOutcome;

/**
 * AI分析Providerの抽象化。Semrush等のSeoDataProviderと同様に、実際のAI API
 * (OpenAI/Anthropic等)固有の呼び出し方法・レスポンス形式をJob/Controllerから
 * 隠蔽する。
 */
interface AiAnalysisProvider
{
    /**
     * @throws AiAnalysisException
     */
    public function analyze(AiAnalysisInput $input): AiAnalysisOutcome;

    public function name(): string;
}
