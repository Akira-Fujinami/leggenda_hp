<?php

namespace App\Services\ComparisonCommentary;

/**
 * 依頼CU-2: 材料(ComparisonCommentaryMaterialBuilder::visible())から、強み・弱みの一文を書くAIの呼び出し口。
 * テストでは固定の出力を返す偽物に差し替える。
 */
interface ComparisonCommentaryProvider
{
    public function name(): string;

    public function model(): ?string;

    public function promptVersion(): string;

    /**
     * @param  array<string, mixed>  $material  プロンプトに載せる材料(visible)
     * @return array{decoded: array<string, mixed>, usage_input_tokens: ?int, usage_output_tokens: ?int}
     *
     * @throws ComparisonCommentaryException
     */
    public function write(array $material): array;
}
