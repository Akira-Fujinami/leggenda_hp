<?php

namespace App\Services\AiAnalysis\Data;

/**
 * AiAnalysisProviderの出力。isMock=trueの場合、これは実際のAI分析結果では
 * ないことを呼び出し側・画面側が必ず判別できるようにするためのフラグであり、
 * モックであることを隠して「事実」として返してはならない。
 */
readonly class AiAnalysisResult
{
    /**
     * @param  list<string>  $strengths
     * @param  list<string>  $weaknesses
     * @param  list<string>  $priorityActions
     * @param  list<string>  $competitorInsights
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
            'strengths' => $this->strengths,
            'weaknesses' => $this->weaknesses,
            'priority_actions' => $this->priorityActions,
            'competitor_insights' => $this->competitorInsights,
            'cautions' => $this->cautions,
            'confidence' => $this->confidence,
            'provider' => $this->provider,
            'model' => $this->model,
            'is_mock' => $this->isMock,
        ];
    }
}
