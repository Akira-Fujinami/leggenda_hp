<?php

namespace App\Services\AiAnalysis\Data;

readonly class AiWeaknessItem
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
