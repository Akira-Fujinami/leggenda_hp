<?php

namespace App\Services\SiteFirstImpression;

/**
 * 依頼CX-2: ページの画像と構造(IDつき)から、求職者目線の第一印象(良い点・悪い点)を1回の呼び出しで書くAIの呼び出し口。
 * テストでは固定の出力を返す偽物に差し替える。
 */
interface SiteFirstImpressionProvider
{
    public function name(): string;

    public function model(): ?string;

    public function promptVersion(): string;

    /**
     * @param  array<string, mixed>  $material  SiteFirstImpressionMaterialBuilder::visible()の戻り値に、
     *                                          images(list<array{label: string, mime: string, base64: string, detail: string}>)を足したもの
     * @return array{decoded: array<string, mixed>, usage_input_tokens: ?int, usage_output_tokens: ?int}
     *
     * @throws \App\Services\ComparisonCommentary\ComparisonCommentaryException
     */
    public function write(array $material): array;
}
