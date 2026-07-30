<?php

namespace App\Services\BrandWheel\Data;

/**
 * 下位要素キーと、それを裏づける原文抜粋を1組で保持する。修正1(2026-07-29)の
 * 指定通り、キーと抜粋を並列配列(matched_sub_elements[] / evidence[])で
 * 持たない ―― 抜粋が原文に存在せず除外すべきとき、どのキーを外すべきかが
 * 並列配列では暗黙の対応に依存して破綻するため、必ず1組のオブジェクトとして扱う。
 */
readonly class BrandWheelSubElementMatch
{
    public function __construct(
        public string $key,
        public string $evidence,
    ) {}

    /**
     * @return array{key: string, evidence: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'evidence' => $this->evidence];
    }
}
