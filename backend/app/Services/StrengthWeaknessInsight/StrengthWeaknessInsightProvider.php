<?php

namespace App\Services\StrengthWeaknessInsight;

/**
 * 依頼DB-1: 強み・弱みの下書き(根拠つきの2行)を書くAIの呼び出し口。テストでは固定の出力を返す偽物に差し替える。
 */
interface StrengthWeaknessInsightProvider
{
    public function name(): string;

    public function model(): ?string;

    public function promptVersion(): string;

    /**
     * @param  array<string, mixed>  $material  StrengthWeaknessInsightMaterialBuilder::build()の戻り値
     * @return array{decoded: array<string, mixed>, usage_input_tokens: ?int, usage_output_tokens: ?int}
     *
     * @throws \App\Services\ComparisonCommentary\ComparisonCommentaryException
     */
    public function write(array $material): array;
}
