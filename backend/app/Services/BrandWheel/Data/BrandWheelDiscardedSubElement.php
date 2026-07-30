<?php

namespace App\Services\BrandWheel\Data;

/**
 * AIが該当すると申告したが、パーサ側の検証で破棄された下位要素の記録。
 * 「無言での切り捨ては禁止」の要件により、なぜ破棄されたかの理由を残す。
 * reason: 'unknown_sub_element'(その軸に存在しないキー) |
 *         'empty_evidence'(抜粋が空文字/非文字列) |
 *         'evidence_not_found'(抜粋が正規化後の原文に実在しない)
 */
readonly class BrandWheelDiscardedSubElement
{
    public function __construct(
        public string $key,
        public ?string $evidence,
        public string $reason,
    ) {}

    /**
     * @return array{key: string, evidence: string|null, reason: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'evidence' => $this->evidence, 'reason' => $this->reason];
    }
}
