<?php

namespace App\Services\AiAnalysis\Data;

readonly class AiPriorityActionItem
{
    /**
     * @param  list<string>  $evidenceMetricKeys
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $priority,
        public string $impact,
        public string $effort,
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
            'priority' => $this->priority,
            'impact' => $this->impact,
            'effort' => $this->effort,
            'evidence_metric_keys' => $this->evidenceMetricKeys,
        ];
    }
}
