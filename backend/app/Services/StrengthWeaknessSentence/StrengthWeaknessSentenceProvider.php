<?php

namespace App\Services\StrengthWeaknessSentence;

/**
 * 依頼CV-2: 機械が選んだ「1項目・1社」の材料から、強み・弱みの一文を1回の呼び出しでまとめて書くAIの呼び出し口。
 * テストでは固定の出力を返す偽物に差し替える。
 */
interface StrengthWeaknessSentenceProvider
{
    public function name(): string;

    public function model(): ?string;

    public function promptVersion(): string;

    /**
     * @param  array<string, mixed>  $material  StrengthWeaknessSentenceMaterialBuilder::visible()の戻り値
     * @return array{decoded: array<string, mixed>, usage_input_tokens: ?int, usage_output_tokens: ?int}
     *
     * @throws \App\Services\ComparisonCommentary\ComparisonCommentaryException
     */
    public function write(array $material): array;
}
