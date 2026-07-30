<?php

namespace App\Services\BrandWheel\Data;

/**
 * Core Value(6軸を貫く一言)がサイトから読み取れたかどうか。readableは
 * evidenceが原文に実在する場合のみtrueになる(AIの自己申告をそのまま
 * 信用しない ―― 軸のstate同様、パーサが検証する)。
 */
readonly class BrandWheelCoreValueResult
{
    public function __construct(
        public bool $readable,
        public ?string $evidence,
    ) {}

    /**
     * @return array{readable: bool, evidence: string|null}
     */
    public function toArray(): array
    {
        return ['readable' => $this->readable, 'evidence' => $this->evidence];
    }
}
