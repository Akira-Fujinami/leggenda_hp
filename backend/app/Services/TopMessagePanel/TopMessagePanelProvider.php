<?php

namespace App\Services\TopMessagePanel;

/**
 * 依頼CZ-2: 1社ぶんの材料(メッセージの文・制度の候補)から、核の一文・本文の文・キーワード・制度を「選ぶ」AIの呼び出し口
 * (1社1回)。文章は書かせない。テストでは固定の出力を返す偽物に差し替える。
 */
interface TopMessagePanelProvider
{
    public function name(): string;

    public function model(): ?string;

    public function promptVersion(): string;

    /**
     * @param  array{company_name: string, sentences: list<array{id: string, text: string}>, programs: list<array{id: string, name: string, excerpt: string}>}  $material
     * @return array{decoded: array<string, mixed>, usage_input_tokens: ?int, usage_output_tokens: ?int}
     *
     * @throws \App\Services\ComparisonCommentary\ComparisonCommentaryException
     */
    public function select(array $material): array;
}
