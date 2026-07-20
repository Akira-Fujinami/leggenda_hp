<?php

namespace App\Services\AiAnalysis\Data;

/**
 * AI入力向けのRecommendation要約。evidence/current_value/recommended_valueの
 * 生データは含めず、AIが文章生成の材料として使う最小限の情報のみ保持する。
 */
readonly class AiRecommendationSummary
{
    public function __construct(
        public string $title,
        public string $categoryKey,
        public string $priority,
        public string $impact,
        public string $effort,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'category_key' => $this->categoryKey,
            'priority' => $this->priority,
            'impact' => $this->impact,
            'effort' => $this->effort,
        ];
    }
}
