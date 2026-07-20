<?php

namespace App\Services\AiAnalysis\Data;

/**
 * AiAnalysisProviderの出力。isMock=trueの場合、これは実際のAI分析結果では
 * ないことを呼び出し側・画面側が必ず判別できるようにするためのフラグであり、
 * モックであることを隠して「事実」として返してはならない。
 *
 * strengths/weaknesses/priorityActions/competitorInsightsの各要素は、実際に
 * AIへ提示したmetric key・website_analysis_idにのみ言及できる
 * (存在しない参照はAiAnalysisResponseParserが構築前に除外する)。
 */
readonly class AiAnalysisResult
{
    /**
     * @param  list<AiStrengthItem>  $strengths
     * @param  list<AiWeaknessItem>  $weaknesses
     * @param  list<AiPriorityActionItem>  $priorityActions
     * @param  list<AiCompetitorInsightItem>  $competitorInsights
     * @param  list<string>  $cautions
     */
    public function __construct(
        public string $summary,
        public array $strengths,
        public array $weaknesses,
        public array $priorityActions,
        public array $competitorInsights,
        public array $cautions,
        public float $confidence,
        public string $provider,
        public ?string $model,
        public bool $isMock,
    ) {
        if ($this->confidence < 0.0 || $this->confidence > 1.0) {
            throw new \InvalidArgumentException('confidence must be between 0.0 and 1.0.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'strengths' => array_map(fn (AiStrengthItem $item) => $item->toArray(), $this->strengths),
            'weaknesses' => array_map(fn (AiWeaknessItem $item) => $item->toArray(), $this->weaknesses),
            'priority_actions' => array_map(fn (AiPriorityActionItem $item) => $item->toArray(), $this->priorityActions),
            'competitor_insights' => array_map(fn (AiCompetitorInsightItem $item) => $item->toArray(), $this->competitorInsights),
            'cautions' => $this->cautions,
            'confidence' => $this->confidence,
            'provider' => $this->provider,
            'model' => $this->model,
            'is_mock' => $this->isMock,
        ];
    }
}
