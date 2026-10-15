<?php

namespace App\Services\BranchSurveyItems;

/**
 * 依頼CT: 枝(またはTOPページ)の本文から、調査14項目のどれが書かれているかを判定するAIの呼び出し口。
 * テストでは固定の出力を返す偽物に差し替える。
 */
interface BranchSurveyItemsProvider
{
    public function name(): string;

    public function model(): ?string;

    public function promptVersion(): string;

    /**
     * @return array{decoded: array<string, mixed>, usage_input_tokens: ?int, usage_output_tokens: ?int}
     *
     * @throws BranchSurveyItemsException
     */
    public function analyze(string $text): array;
}
