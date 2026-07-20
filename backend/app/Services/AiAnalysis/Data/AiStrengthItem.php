<?php

namespace App\Services\AiAnalysis\Data;

/**
 * evidenceMetricKeysは、AiAnalysisInputFactoryが渡したimportant_metrics/
 * unavailable_metrics/error_metricsのkeyのうち実在するものだけを保持する
 * (AI出力の検証済みの結果 ―― 存在しないkeyはAiAnalysisResponseParserで除外済み)。
 */
readonly class AiStrengthItem
{
    /**
     * @param  list<string>  $evidenceMetricKeys
     */
    public function __construct(
        public string $title,
        public string $description,
        public array $evidenceMetricKeys = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'evidence_metric_keys' => $this->evidenceMetricKeys,
        ];
    }
}
